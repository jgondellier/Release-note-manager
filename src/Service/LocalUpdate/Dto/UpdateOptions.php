<?php

declare(strict_types=1);

namespace App\Service\LocalUpdate\Dto;

final class UpdateOptions
{
    /**
     * @param list<int>|null $commands index des commandes à lancer (null = toutes)
     */
    public function __construct(
        public readonly ?string $ref = null,
        public readonly bool $deploy = true,
        public readonly ?array $commands = null,
        public readonly bool $dryRun = false,
        public readonly string $trigger = 'web',
    ) {
    }
}
