<?php

declare(strict_types=1);

namespace App\Service\Jira\Dto;

use App\Service\Jira\AdfTextExtractor;

final class JiraComment
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $author,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly string $body,
    ) {
    }

    /** @param array<string, mixed> $raw commentaire Jira (corps ADF sur Cloud, texte sur Server) */
    public static function fromApi(array $raw, AdfTextExtractor $adf): self
    {
        $body = $raw['body'] ?? '';
        $text = \is_array($body) ? $adf->extract($body) : trim((string) $body);
        $created = null;
        if (isset($raw['created']) && \is_string($raw['created'])) {
            try {
                $created = new \DateTimeImmutable($raw['created']);
            } catch (\Exception) {
                $created = null;
            }
        }

        return new self(
            (string) ($raw['id'] ?? ''),
            isset($raw['author']['displayName']) ? (string) $raw['author']['displayName'] : null,
            $created,
            $text,
        );
    }
}
