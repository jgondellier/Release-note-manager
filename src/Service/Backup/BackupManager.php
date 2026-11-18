<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\BackupSettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sauvegarde de Release Note Manager dans une archive zip déposée dans un répertoire :
 * - la base de données : copie cohérente du fichier SQLite (VACUUM INTO), script SQL pour
 *   MySQL / MariaDB, sinon export JSON de chaque table ;
 * - les fichiers et dossiers choisis (relatifs au dossier de l'application ou absolus, * accepté) ;
 * - un manifeste (date, base, contenu).
 * Les sauvegardes au-delà du nombre conservé sont supprimées (seulement celles de Release Note Manager).
 */
final class BackupManager
{
    public const PREFIX = 'rnm_sauvegarde_';
    private const INSERT_BATCH = 200;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function projectDir(): string
    {
        return $this->projectDir;
    }

    /**
     * Répertoire de destination contrôlé (créé s'il n'existe pas).
     *
     * @throws BackupException
     */
    public function checkDestination(?string $destination, bool $create = true): string
    {
        $destination = trim((string) $destination);
        if ('' === $destination) {
            throw new BackupException('Indiquez le répertoire de destination dans les paramètres.');
        }
        if (!$this->isAbsolute($destination)) {
            throw new BackupException(sprintf('Le répertoire « %s » doit être un chemin absolu (ex. D:/Sauvegardes/ReleaseNoteManager).', $destination));
        }
        $normalized = $this->normalize($destination);
        foreach (['public', 'var/cache'] as $forbidden) {
            $dir = $this->normalize($this->projectDir.'/'.$forbidden);
            if ($normalized === $dir || str_starts_with($normalized.'/', $dir.'/')) {
                throw new BackupException(sprintf('Le répertoire de destination ne peut pas être dans %s/ de l\'application (%s).', $forbidden, 'public' === $forbidden ? 'il serait accessible depuis le navigateur' : 'vidé par cache:clear'));
            }
        }
        if (!is_dir($destination)) {
            if (!$create) {
                return $destination;   // créé à la première sauvegarde
            }
            if (!@mkdir($destination, 0775, true) && !is_dir($destination)) {
                throw new BackupException(sprintf('Impossible de créer le répertoire « %s ».', $destination));
            }
        }
        if (!is_writable($destination)) {
            throw new BackupException(sprintf('Le répertoire « %s » n\'est pas modifiable (droits insuffisants).', $destination));
        }

        return $destination;
    }

    /**
     * Lance une sauvegarde.
     *
     * @throws BackupException
     */
    public function run(BackupSettings $settings): BackupResult
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new BackupException('Extension PHP zip absente : activez extension=zip dans php.ini.');
        }
        $start = microtime(true);
        $destination = $this->checkDestination($settings->getDestination());
        $stamp = date('Ymd_His');
        $target = $destination.DIRECTORY_SEPARATOR.self::PREFIX.$stamp.'.zip';
        for ($i = 2; file_exists($target); ++$i) {   // deux sauvegardes dans la même seconde
            $target = $destination.DIRECTORY_SEPARATOR.self::PREFIX.$stamp.'_'.$i.'.zip';
        }
        $partial = $target.'.part';
        $result = new BackupResult($target);
        $temp = [];

        $zip = new \ZipArchive();
        if (true !== $zip->open($partial, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new BackupException(sprintf('Impossible de créer l\'archive « %s ».', $partial));
        }
        try {
            if ($settings->isIncludeDatabase()) {
                foreach ($this->dumpDatabase($result) as $entry => $file) {
                    $zip->addFile($file, $entry);
                    $temp[] = $file;
                    $result->entries[] = ['path' => $entry, 'size' => filesize($file), 'kind' => 'base'];
                }
            }
            foreach ($settings->getPathList() as $pattern) {
                $this->addPaths($zip, $pattern, $destination, $result);
            }
            $zip->addFromString('manifest.json', (string) json_encode([
                'application' => 'Release Note Manager',
                'date' => date(DATE_ATOM),
                'database' => $result->databaseFormat,
                'source' => $this->projectDir,
                'entries' => $result->entries,
                'warnings' => $result->warnings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!$zip->close()) {
                throw new BackupException('Écriture de l\'archive impossible : '.$zip->getStatusString());
            }
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($partial);
            throw $e instanceof BackupException ? $e : new BackupException('Sauvegarde interrompue : '.$e->getMessage(), 0, $e);
        } finally {
            foreach ($temp as $file) {
                @unlink($file);
            }
        }
        if (!@rename($partial, $target)) {
            @unlink($partial);
            throw new BackupException(sprintf('Impossible de renommer l\'archive en « %s ».', $target));
        }

        $result->size = (int) filesize($target);
        $result->durationMs = (int) round((microtime(true) - $start) * 1000);
        $result->deleted = $this->prune($destination, $settings->getKeep());

        return $result;
    }

    /**
     * Sauvegardes présentes dans le répertoire, de la plus récente à la plus ancienne.
     *
     * @return list<array{name: string, path: string, size: int, date: \DateTimeImmutable}>
     */
    public function list(?string $destination): array
    {
        if (null === $destination || !is_dir($destination)) {
            return [];
        }
        $out = [];
        foreach (glob($destination.DIRECTORY_SEPARATOR.self::PREFIX.'*.zip') ?: [] as $file) {
            $out[] = ['name' => basename($file), 'path' => $file, 'size' => (int) filesize($file), 'date' => (new \DateTimeImmutable())->setTimestamp((int) filemtime($file))];
        }
        usort($out, static fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $out;
    }

    /** Chemin d'une sauvegarde du répertoire, par son nom (aucun autre fichier n'est accessible) */
    public function find(?string $destination, string $name): ?string
    {
        foreach ($this->list($destination) as $backup) {
            if ($backup['name'] === $name) {
                return $backup['path'];
            }
        }

        return null;
    }

    /**
     * @return array<string, string> nom dans l'archive => fichier temporaire
     */
    private function dumpDatabase(BackupResult $result): array
    {
        $platform = $this->connection->getDatabasePlatform();
        $tmp = tempnam(sys_get_temp_dir(), 'rnm_db_');
        @unlink($tmp);

        if ($platform instanceof SQLitePlatform) {
            $params = $this->connection->getParams();
            $path = $params['path'] ?? null;
            $result->databaseFormat = 'SQLite';
            try {
                // Copie cohérente même si la base est utilisée (SQLite 3.27+)
                $this->connection->executeStatement('VACUUM INTO '.$this->connection->quote($tmp));
            } catch (\Throwable $e) {
                if (null === $path || !is_file($path) || !@copy($path, $tmp)) {
                    throw new BackupException('Copie de la base SQLite impossible : '.$e->getMessage(), 0, $e);
                }
                $result->warnings[] = 'Base SQLite copiée telle quelle (VACUUM INTO indisponible : '.$e->getMessage().').';
            }

            return ['database/'.(null !== $path ? basename($path) : 'data.db') => $tmp];
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            $result->databaseFormat = 'MySQL / MariaDB (script SQL)';
            $this->dumpMysql($tmp);

            return ['database/release_notes.sql' => $tmp];
        }

        $result->databaseFormat = 'JSON (une entrée par table)';
        $files = [];
        foreach ($this->connection->createSchemaManager()->listTableNames() as $table) {
            $file = tempnam(sys_get_temp_dir(), 'rnm_tbl_');
            file_put_contents($file, json_encode($this->connection->fetchAllAssociative('SELECT * FROM '.$this->connection->quoteIdentifier($table)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $files['database/'.$table.'.json'] = $file;
        }

        return $files;
    }

    private function dumpMysql(string $file): void
    {
        $out = fopen($file, 'w');
        fwrite($out, "-- Sauvegarde Release Note Manager du ".date('d/m/Y H:i:s')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        foreach ($this->connection->createSchemaManager()->listTableNames() as $table) {
            $q = $this->connection->quoteIdentifier($table);
            $create = $this->connection->fetchNumeric('SHOW CREATE TABLE '.$q);
            fwrite($out, "DROP TABLE IF EXISTS $q;\n".$create[1].";\n\n");
            $batch = [];
            foreach ($this->connection->iterateNumeric('SELECT * FROM '.$q) as $row) {
                $batch[] = '('.implode(', ', array_map(fn ($v) => null === $v ? 'NULL' : $this->connection->quote((string) $v), $row)).')';
                if (count($batch) >= self::INSERT_BATCH) {
                    fwrite($out, "INSERT INTO $q VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ([] !== $batch) {
                fwrite($out, "INSERT INTO $q VALUES\n".implode(",\n", $batch).";\n");
            }
            fwrite($out, "\n");
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($out);
    }

    private function addPaths(\ZipArchive $zip, string $pattern, string $destination, BackupResult $result): void
    {
        $absolute = $this->isAbsolute($pattern) ? $pattern : $this->projectDir.'/'.ltrim($pattern, '/\\');
        $matches = str_contains($absolute, '*') || str_contains($absolute, '?') ? (glob($absolute) ?: []) : (file_exists($absolute) ? [$absolute] : []);
        if ([] === $matches) {
            $result->warnings[] = sprintf('« %s » : introuvable, ignoré.', $pattern);

            return;
        }
        $dest = $this->normalize($destination);
        foreach ($matches as $path) {
            $files = is_dir($path) ? $this->filesIn($path) : [$path];
            foreach ($files as $file) {
                $normalized = $this->normalize($file);
                if (str_starts_with($normalized.'/', $dest.'/')) {
                    continue;   // jamais les sauvegardes elles-mêmes
                }
                if (!is_readable($file)) {
                    $result->warnings[] = sprintf('« %s » : illisible, ignoré.', $file);
                    continue;
                }
                $entry = $this->entryName($normalized);
                if ($zip->locateName($entry) !== false) {
                    continue;
                }
                $zip->addFile($file, $entry);
                $result->entries[] = ['path' => $entry, 'size' => (int) filesize($file), 'kind' => 'fichier'];
            }
        }
    }

    /** @return list<string> */
    private function filesIn(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** Nom dans l'archive : fichiers/<chemin relatif à l'application>, ou externe/<chemin absolu sans le lecteur> */
    private function entryName(string $normalized): string
    {
        $project = $this->normalize($this->projectDir);
        if (str_starts_with($normalized, $project.'/')) {
            return 'fichiers/'.substr($normalized, strlen($project) + 1);
        }

        return 'externe/'.ltrim((string) preg_replace('#^([A-Za-z]):#', '$1', $normalized), '/');
    }

    /** @return list<string> sauvegardes supprimées */
    private function prune(string $destination, int $keep): array
    {
        if ($keep <= 0) {
            return [];
        }
        $deleted = [];
        foreach (array_slice($this->list($destination), $keep) as $old) {
            if (@unlink($old['path'])) {
                $deleted[] = $old['name'];
            }
        }

        return $deleted;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || 1 === preg_match('#^[A-Za-z]:[/\\\\]#', $path);
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $real = realpath($path);

        return false !== $real ? rtrim(str_replace('\\', '/', $real), '/') : $path;
    }
}
