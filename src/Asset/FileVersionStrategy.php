<?php

declare(strict_types=1);

namespace App\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Version des fichiers CSS/JS = date de modification du fichier : après une mise à jour de l'application,
 * le navigateur recharge automatiquement les feuilles de style modifiées (plus d'ancien CSS en cache).
 */
final class FileVersionStrategy implements VersionStrategyInterface
{
    /** @var array<string, string> */
    private array $versions = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDirectory,
    ) {
    }

    public function getVersion(string $path): string
    {
        if (isset($this->versions[$path])) {
            return $this->versions[$path];
        }
        $file = $this->publicDirectory . '/' . ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        $mtime = is_file($file) ? filemtime($file) : false;

        return $this->versions[$path] = false === $mtime ? '' : base_convert((string) $mtime, 10, 36);
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);
        if ('' === $version) {
            return $path;
        }

        return $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . $version;
    }
}
