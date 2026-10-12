<?php

declare(strict_types=1);

namespace App\Service\Jira\Dto;

final class EpicReference
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
    ) {
    }
}
