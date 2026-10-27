<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Entity\ExternalConnection;
use App\Service\Jira\Dto\JiraComment;
use App\Service\Jira\Dto\JiraIssue;

/**
 * Normalise une réponse « issue » Jira (Cloud v3 ou Server v2) en JiraIssue.
 */
final class JiraIssueNormalizer
{
    /** Noms usuels du type Epic selon la langue de l'instance. */
    private const EPIC_TYPE_NAMES = ['epic', 'épopée', 'epopee', 'épique', 'epique'];

    public function __construct(private readonly AdfTextExtractor $adf)
    {
    }

    /** Champs demandés à Jira (le champ Epic Link est ajouté s'il est configuré). */
    public static function fields(ExternalConnection $connection): array
    {
        $fields = ['summary', 'issuetype', 'status', 'priority', 'components', 'labels', 'fixVersions', 'parent', 'description', 'updated', 'resolution', 'issuelinks'];
        if (null !== $connection->getJiraEpicLinkField()) {
            $fields[] = $connection->getJiraEpicLinkField();
        }

        return $fields;
    }

    /** @param array<string, mixed> $issue */
    public function normalize(array $issue, ExternalConnection $connection): JiraIssue
    {
        if (!isset($issue['id'], $issue['key']) || !\is_array($issue['fields'] ?? null)) {
            throw new \UnexpectedValueException('Ticket Jira incomplet dans la réponse.');
        }
        $f = $issue['fields'];
        $issueType = \is_array($f['issuetype'] ?? null) ? $f['issuetype'] : [];
        $parent = \is_array($f['parent'] ?? null) ? $f['parent'] : null;
        $parentType = \is_array($parent['fields']['issuetype'] ?? null) ? $parent['fields']['issuetype'] : [];

        $epicLinkKey = null;
        $epicField = $connection->getJiraEpicLinkField();
        if (null !== $epicField && isset($f[$epicField])) {
            $value = $f[$epicField];
            // Server : clé de l'Epic (string). Certaines instances renvoient un objet {key: …}.
            $epicLinkKey = \is_string($value) ? $value : (\is_array($value) && isset($value['key']) ? (string) $value['key'] : null);
        }

        $statusCategory = $f['status']['statusCategory']['key'] ?? null;

        return new JiraIssue(
            id: (string) $issue['id'],
            key: (string) $issue['key'],
            issueType: (string) ($issueType['name'] ?? 'Inconnu'),
            isEpic: $this->isEpicType($issueType),
            isSubtask: (bool) ($issueType['subtask'] ?? false),
            summary: trim((string) ($f['summary'] ?? '')),
            description: $this->description($f['description'] ?? null),
            status: isset($f['status']['name']) ? (string) $f['status']['name'] : null,
            statusCategory: \is_string($statusCategory) ? $statusCategory : null,
            priority: isset($f['priority']['name']) ? (string) $f['priority']['name'] : null,
            components: $this->names($f['components'] ?? []),
            labels: array_values(array_map('strval', array_filter((array) ($f['labels'] ?? []), 'is_scalar'))),
            fixVersions: $this->versions($f['fixVersions'] ?? []),
            parentKey: isset($parent['key']) ? (string) $parent['key'] : null,
            parentIsEpic: null !== $parent && $this->isEpicType($parentType),
            parentSummary: isset($parent['fields']['summary']) ? (string) $parent['fields']['summary'] : null,
            epicLinkKey: $epicLinkKey,
            url: JiraApi::browseUrl($connection, (string) $issue['key']),
            updatedAt: $this->dateTime($f['updated'] ?? null),
            diagnostic: [
                'id' => (string) $issue['id'],
                'key' => (string) $issue['key'],
                'issuetype' => $issueType['name'] ?? null,
                'hierarchyLevel' => $issueType['hierarchyLevel'] ?? null,
                'status' => $f['status']['name'] ?? null,
                'statusCategory' => $statusCategory,
                'resolution' => $f['resolution']['name'] ?? null,
                'parent' => $parent['key'] ?? null,
                'parentType' => $parentType['name'] ?? null,
                'epicLink' => $epicLinkKey,
                'updated' => $f['updated'] ?? null,
            ],
            linkedIssues: $this->links($f['issuelinks'] ?? []),
            comments: $this->comments($f['comment'] ?? null),
        );
    }

    /**
     * Commentaires renvoyés avec la recherche (champ « comment »). Null si absents ou tronqués par Jira :
     * l'appelant les relit alors ticket par ticket.
     *
     * @return list<JiraComment>|null
     */
    private function comments(mixed $field): ?array
    {
        if (!\is_array($field) || !\is_array($field['comments'] ?? null)) {
            return null;
        }
        $raws = array_values(array_filter($field['comments'], 'is_array'));
        if ((int) ($field['total'] ?? \count($raws)) > \count($raws)) {
            return null;
        }

        return array_map(fn (array $raw) => JiraComment::fromApi($raw, $this->adf), $raws);
    }

    /**
     * Tickets liés (liens Jira « bloque », « est lié à »…), avec le libellé du lien vu depuis ce ticket.
     *
     * @return list<array{key: string, summary: string, type: string, status: ?string, statusCategory: ?string, priority: ?string, link: string}>
     */
    private function links(mixed $issueLinks): array
    {
        $links = [];
        foreach ((array) $issueLinks as $link) {
            if (!\is_array($link)) {
                continue;
            }
            [$other, $label] = isset($link['outwardIssue'])
                ? [$link['outwardIssue'], $link['type']['outward'] ?? $link['type']['name'] ?? 'lié à']
                : [$link['inwardIssue'] ?? null, $link['type']['inward'] ?? $link['type']['name'] ?? 'lié à'];
            if (!\is_array($other) || !isset($other['key'])) {
                continue;
            }
            $f = \is_array($other['fields'] ?? null) ? $other['fields'] : [];
            $category = $f['status']['statusCategory']['key'] ?? null;
            $links[] = [
                'key' => (string) $other['key'],
                'summary' => (string) ($f['summary'] ?? ''),
                'type' => (string) ($f['issuetype']['name'] ?? ''),
                'status' => isset($f['status']['name']) ? (string) $f['status']['name'] : null,
                'statusCategory' => \is_string($category) ? $category : null,
                'priority' => isset($f['priority']['name']) ? (string) $f['priority']['name'] : null,
                'link' => (string) $label,
            ];
        }

        return $links;
    }

    /** @param array<string, mixed> $issueType */
    private function isEpicType(array $issueType): bool
    {
        // Cloud : hierarchyLevel = 1 pour les Epics. Server : comparaison du nom.
        if (isset($issueType['hierarchyLevel']) && 1 === (int) $issueType['hierarchyLevel']) {
            return true;
        }
        $name = mb_strtolower(trim((string) ($issueType['name'] ?? '')));

        return \in_array($name, self::EPIC_TYPE_NAMES, true);
    }

    private function description(mixed $value): ?string
    {
        if (\is_array($value)) {
            $text = $this->adf->extract($value);
        } elseif (\is_string($value)) {
            $text = trim($value);
        } else {
            return null;
        }

        return '' === $text ? null : $text;
    }

    /** @return list<string> */
    private function names(mixed $items): array
    {
        $names = [];
        foreach ((array) $items as $item) {
            if (\is_array($item) && isset($item['name'])) {
                $names[] = (string) $item['name'];
            }
        }

        return $names;
    }

    /** @return list<array{id: string, name: string}> */
    private function versions(mixed $items): array
    {
        $versions = [];
        foreach ((array) $items as $item) {
            if (\is_array($item) && isset($item['id'], $item['name'])) {
                $versions[] = ['id' => (string) $item['id'], 'name' => (string) $item['name']];
            }
        }

        return $versions;
    }

    private function dateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
