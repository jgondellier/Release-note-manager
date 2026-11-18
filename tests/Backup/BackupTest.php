<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Entity\BackupSettings;
use App\Repository\BackupSettingsRepository;
use App\Service\Backup\BackupException;
use App\Service\Backup\BackupManager;
use App\Tests\Support\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class BackupTest extends WebTestCaseBase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/rnm_backup_test_'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function token(string $action): string
    {
        return (string) $this->client->getCrawler()->filter('form[action$="'.$action.'"] input[name="_token"]')->attr('value');
    }

    private function manager(): BackupManager
    {
        return static::getContainer()->get(BackupManager::class);
    }

    public function testConfigureRunAndDownloadFromAdministration(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/');
        $this->client->click($crawler->filter('nav a[href="/backups"]')->link());
        self::assertSelectorTextContains('h1', 'Sauvegarde');
        self::assertSelectorExists('form[action$="/backups/run"] button[disabled]');

        // Paramètres
        $this->client->request('POST', '/backups/settings', ['_token' => $this->token('/backups/settings'), 'destination' => $this->dir,
            'paths' => ".env.test\nconfig/packages/\n# commentaire\nabsent.txt", 'includeDatabase' => '1', 'keep' => '2']);
        self::assertResponseRedirects('/backups');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Paramètres de sauvegarde enregistrés', $crawler->filter('.alert-success')->text());
        self::assertStringContainsString('il sera créé à la première sauvegarde', $crawler->filter('#destination-check')->text());

        // Sauvegarde
        $this->client->request('POST', '/backups/run', ['_token' => $this->token('/backups/run')]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Sauvegarde créée', $crawler->filter('.alert-success')->text());
        self::assertStringContainsString('« absent.txt » : introuvable', $crawler->filter('main')->text());
        $files = glob($this->dir.'/'.BackupManager::PREFIX.'*.zip');
        self::assertCount(1, $files);

        $zip = new \ZipArchive();
        $zip->open($files[0]);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = $zip->getNameIndex($i);
        }
        self::assertContains('database/test.db', $names);
        self::assertContains('fichiers/.env.test', $names);
        self::assertContains('fichiers/config/packages/doctrine.yaml', $names);
        self::assertContains('manifest.json', $names);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        self::assertSame('Release Note Manager', $manifest['application']);
        self::assertSame('SQLite', $manifest['database']);
        $db = (string) tempnam(sys_get_temp_dir(), 'db');
        file_put_contents($db, $zip->getFromName('database/test.db'));
        $zip->close();
        $pdo = new \PDO('sqlite:'.$db);
        self::assertSame($this->dir, $pdo->query('SELECT destination FROM backup_settings')->fetchColumn(), 'base restaurable');
        unset($pdo);
        unlink($db);

        // Liste et téléchargement
        $crawler = $this->client->request('GET', '/backups');
        $download = (string) $crawler->filter('#backups a')->first()->attr('href');
        $this->client->request('GET', $download);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame('application/zip', $this->client->getResponse()->headers->get('Content-Type'));
        $this->client->request('GET', '/backups/download/'.BackupManager::PREFIX.'20000101_000000.zip');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/backups/download/autre.zip');
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidCsrfIsRefused(): void
    {
        $this->login();
        $this->client->request('POST', '/backups/run', ['_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRetentionKeepsOnlyTheNewest(): void
    {
        mkdir($this->dir);
        touch($this->dir.'/'.BackupManager::PREFIX.'20200101_000000.zip');
        touch($this->dir.'/'.BackupManager::PREFIX.'20210101_000000.zip');
        touch($this->dir.'/autre_fichier.zip');
        $settings = (new BackupSettings())->setDestination($this->dir)->setPaths('')->setKeep(2);

        $first = $this->manager()->run($settings);
        $second = $this->manager()->run($settings);

        self::assertNotSame($first->file, $second->file, 'deux sauvegardes dans la même seconde');
        self::assertCount(2, glob($this->dir.'/'.BackupManager::PREFIX.'*.zip') ?: []);
        self::assertFileExists($this->dir.'/autre_fichier.zip', 'seules les sauvegardes de l\'application sont supprimées');
        self::assertFileDoesNotExist($this->dir.'/'.BackupManager::PREFIX.'20200101_000000.zip');
    }

    public function testRefusedDestinationsAndRemoteAccess(): void
    {
        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        foreach (['relatif/dossier' => 'chemin absolu', $projectDir.'/public/sauvegardes' => 'public/', $projectDir.'/var/cache/x' => 'var/cache/'] as $destination => $message) {
            try {
                $this->manager()->run((new BackupSettings())->setDestination($destination));
                self::fail($destination.' accepté');
            } catch (BackupException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->login();
        $this->client->request('GET', '/backups', server: ['REMOTE_ADDR' => '10.1.2.3']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousAccessIsRefused(): void
    {
        $this->client->request('GET', '/backups');
        self::assertResponseRedirects('/login');
    }

    public function testCommand(): void
    {
        static::getContainer()->get(BackupSettingsRepository::class)->getSettings()->setPaths('.env.test');
        $this->em->flush();
        $tester = new CommandTester((new Application(static::$kernel))->find('app:backup'));

        self::assertSame(1, $tester->execute([]), 'pas de destination');
        self::assertSame(0, $tester->execute(['--destination' => $this->dir]));
        self::assertStringContainsString('Sauvegarde créée', $tester->getDisplay());
        self::assertCount(1, glob($this->dir.'/'.BackupManager::PREFIX.'*.zip') ?: []);
        self::assertNull(static::getContainer()->get(BackupSettingsRepository::class)->getSettings()->getDestination(), 'paramètres inchangés');
    }
}
