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
    ) {
    }
}
