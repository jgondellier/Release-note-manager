<?php

declare(strict_types=1);

namespace App\Service\Confluence\Dto;

final class ConfluencePage
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly int $version,
        public readonly string $url,
        /** Page parente (null : racine de l'espace, ou non renvoyée par l'API). */
        public readonly ?string $parentId = null,
        /** Contenu au format de stockage, seulement si demandé (getPage avec $withBody). */
        public readonly ?string $storage = null,
    ) {
    }
}
