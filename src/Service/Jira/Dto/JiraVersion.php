<?php

declare(strict_types=1);

namespace App\Service\Jira\Dto;

/**
 * Version (Fix Version) d'un projet Jira.
 */
final class JiraVersion
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $description,
        public readonly bool $released,
        public readonly bool $archived,
        public readonly ?\DateTimeImmutable $releaseDate,
        public readonly ?\DateTimeImmutable $startDate,
    ) {
    }

    /** @param array<string, mixed> $data réponse /rest/api/x/version */
    public static function fromApi(array $data): self
    {
        if (!isset($data['id'], $data['name'])) {
            throw new \UnexpectedValueException('Version Jira incomplète (id/name manquants).');
        }

        return new self(
            (string) $data['id'],
            (string) $data['name'],
            isset($data['description']) && '' !== trim((string) $data['description']) ? (string) $data['description'] : null,
            (bool) ($data['released'] ?? false),
            (bool) ($data['archived'] ?? false),
            self::date($data['releaseDate'] ?? null),
            self::date($data['startDate'] ?? null),
        );
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return false === $date ? null : $date;
    }
}
