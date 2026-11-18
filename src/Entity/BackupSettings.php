<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BackupSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/** Paramètres de la sauvegarde (une seule ligne) */
#[ORM\Entity(repositoryClass: BackupSettingsRepository::class)]
#[ORM\Table(name: 'backup_settings')]
class BackupSettings
{
    /** .env.local contient APP_ENCRYPTION_KEY : sans elle, les secrets Jira/Confluence de la base sont irrécupérables. */
    public const DEFAULT_PATHS = ".env.local";

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Répertoire de destination (chemin absolu, hors des dossiers public/ et var/cache/) */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $destination = null;

    /** Fichiers et dossiers à sauvegarder, un par ligne (relatifs au dossier de l'application ou absolus, * accepté) */
    #[ORM\Column(type: 'text')]
    private string $paths = self::DEFAULT_PATHS;

    #[ORM\Column]
    private bool $includeDatabase = true;

    /** Nombre de sauvegardes conservées dans le répertoire (0 = toutes) */
    #[ORM\Column(name: 'backups_kept')]
    private int $keep = 10;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDestination(): ?string
    {
        return $this->destination;
    }

    public function setDestination(?string $destination): static
    {
        $destination = trim((string) $destination);
        $this->destination = '' === $destination ? null : (rtrim($destination, '/\\') ?: $destination);

        return $this;
    }

    public function getPaths(): string
    {
        return $this->paths;
    }

    public function setPaths(?string $paths): static
    {
        $this->paths = trim(str_replace("\r", '', (string) $paths));

        return $this;
    }

    /** @return list<string> */
    public function getPathList(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $this->paths)), static fn (string $l) => '' !== $l && !str_starts_with($l, '#')));
    }

    public function isIncludeDatabase(): bool
    {
        return $this->includeDatabase;
    }

    public function setIncludeDatabase(bool $includeDatabase): static
    {
        $this->includeDatabase = $includeDatabase;

        return $this;
    }

    public function getKeep(): int
    {
        return $this->keep;
    }

    public function setKeep(int $keep): static
    {
        $this->keep = max(0, $keep);

        return $this;
    }
}
