<?php

declare(strict_types=1);

namespace App\Service\Backup;

/** Résultat d'une sauvegarde */
final class BackupResult
{
    /** @var list<array{path: string, size: int, kind: string}> */
    public array $entries = [];
    /** @var list<string> */
    public array $warnings = [];
    /** @var list<string> */
    public array $deleted = [];
    public string $databaseFormat = 'non sauvegardée';
    public int $size = 0;
    public int $durationMs = 0;

    public function __construct(public readonly string $file)
    {
    }

    public function fileCount(): int
    {
        return \count(array_filter($this->entries, static fn (array $e) => 'fichier' === $e['kind']));
    }
}
