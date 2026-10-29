<?php

declare(strict_types=1);

namespace App\Service\Jira;

/**
 * Construction sûre des requêtes JQL (échappement des valeurs).
 */
final class JiraJqlBuilder
{
    private const ISSUE_KEY_PATTERN = '/^[A-Z][A-Z0-9_]*-\d+$/i';

    /** Échappe une valeur littérale JQL entre guillemets doubles. */
    public static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Tickets d'un projet portant une Fix Version donnée.
     * L'identifiant numérique de version est utilisé de préférence : il évite toute
     * ambiguïté (versions homonymes, caractères spéciaux) ; à défaut, le nom est échappé.
     * Les sous-tâches sont exclues (fonction JQL standard, Cloud et Server/DC).
     */
    public static function forFixVersion(string $projectKey, string $versionIdOrName): string
    {
        $version = ctype_digit($versionIdOrName) ? $versionIdOrName : self::quote($versionIdOrName);

        return sprintf(
            'project = %s AND fixVersion = %s AND issuetype not in subTaskIssueTypes() ORDER BY issuetype ASC, key ASC',
            self::quote($projectKey),
            $version
        );
    }

    /** @param list<string> $keys */
    public static function forKeys(array $keys): string
    {
        $valid = array_values(array_unique(array_filter($keys, static fn (string $k) => 1 === preg_match(self::ISSUE_KEY_PATTERN, $k))));
        if ([] === $valid) {
            throw new \InvalidArgumentException('Aucune clé de ticket Jira valide.');
        }

        return sprintf('key in (%s) ORDER BY key ASC', implode(', ', array_map(self::quote(...), $valid)));
    }

    public static function isValidIssueKey(string $key): bool
    {
        return 1 === preg_match(self::ISSUE_KEY_PATTERN, $key);
    }
}
