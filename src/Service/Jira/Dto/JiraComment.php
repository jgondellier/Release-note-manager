<?php

declare(strict_types=1);

namespace App\Service\Jira\Dto;

final class JiraComment
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $author,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly string $body,
    ) {
    }
}
