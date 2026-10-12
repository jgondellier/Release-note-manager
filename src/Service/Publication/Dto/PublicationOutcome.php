<?php

declare(strict_types=1);

namespace App\Service\Publication\Dto;

use App\Enum\PublicationFormat;
use App\Enum\PublicationTarget;

final class PublicationOutcome
{
    public const SUCCESS = 'success';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    public function __construct(
        public readonly PublicationFormat $format,
        public readonly PublicationTarget $target,
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $pageUrl = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return self::SUCCESS === $this->status;
    }
}
