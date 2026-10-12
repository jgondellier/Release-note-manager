<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Entity\ExternalConnection;
use App\Service\Http\ExternalApiException;
use App\Service\Jira\Dto\EpicReference;
use App\Service\Jira\Dto\JiraIssue;

/**
 * Détermine l'Epic de chaque ticket, quel que soit le modèle Jira :
 *
 * 1. le ticket est lui-même un Epic ;
 * 2. relation « parent » vers un Epic (Jira Cloud, hiérarchie unifiée) ;
 * 3. champ personnalisé « Epic Link » configuré sur la connexion (Jira Server/DC) ;
 * 4. sous-tâche : héritage de l'Epic de son ticket parent.
 *
 * Les titres d'Epic manquants sont récupérés en lot ; un Epic inaccessible
 * reçoit sa clé comme titre (aucun échec bloquant).
 */
class JiraEpicResolver
{
    public function __construct(private readonly JiraIssueService $issueService)
    {
    }

    /**
     * @param list<JiraIssue> $issues
     *
     * @return array<string, EpicReference|null> Epic par clé de ticket
     */
    public function resolve(ExternalConnection $connection, array $issues): array
    {
        $byKey = [];
        foreach ($issues as $issue) {
            $byKey[$issue->key] = $issue;
        }

        /** @var array<string, string|null> $epicKeys */
        $epicKeys = [];
        /** @var array<string, string> $knownNames */
        $knownNames = [];
        $subtasks = [];

        foreach ($issues as $issue) {
            [$epicKey, $name] = $this->direct($issue);
            if (null !== $epicKey) {
                $epicKeys[$issue->key] = $epicKey;
                if (null !== $name) {
                    $knownNames[$epicKey] = $name;
                }
            } elseif ($issue->isSubtask && null !== $issue->parentKey) {
                $subtasks[] = $issue;
                $epicKeys[$issue->key] = null;
            } else {
                $epicKeys[$issue->key] = null;
            }
        }

        // Sous-tâches : Epic du parent (chargé si nécessaire).
        $missingParents = [];
        foreach ($subtasks as $subtask) {
            if (!isset($byKey[$subtask->parentKey])) {
                $missingParents[] = (string) $subtask->parentKey;
            }
        }
        $parents = [] === $missingParents ? [] : $this->safeFindByKeys($connection, $missingParents);
        foreach ($subtasks as $subtask) {
            $parent = $byKey[$subtask->parentKey] ?? $parents[$subtask->parentKey] ?? null;
            if (null === $parent) {
                continue;
            }
            [$epicKey, $name] = $this->direct($parent);
            if (null !== $epicKey) {
                $epicKeys[$subtask->key] = $epicKey;
                if (null !== $name) {
                    $knownNames[$epicKey] = $name;
                }
            }
        }

        // Titres d'Epic manquants.
        $unnamed = [];
        foreach (array_unique(array_filter($epicKeys)) as $epicKey) {
            if (!isset($knownNames[$epicKey])) {
                if (isset($byKey[$epicKey])) {
                    $knownNames[$epicKey] = $byKey[$epicKey]->summary;
                } else {
                    $unnamed[] = $epicKey;
                }
            }
        }
        if ([] !== $unnamed) {
            foreach ($this->safeFindByKeys($connection, $unnamed) as $key => $epic) {
                $knownNames[$key] = $epic->summary;
            }
        }

        $result = [];
        foreach ($epicKeys as $issueKey => $epicKey) {
            $result[$issueKey] = null === $epicKey
                ? null
                : new EpicReference($epicKey, '' !== ($knownNames[$epicKey] ?? '') ? $knownNames[$epicKey] : 'Epic ' . $epicKey);
        }

        return $result;
    }

    /** @return array{0: ?string, 1: ?string} [clé de l'Epic, titre connu] */
    private function direct(JiraIssue $issue): array
    {
        if ($issue->isEpic) {
            return [$issue->key, $issue->summary];
        }
        if (null !== $issue->parentKey && $issue->parentIsEpic) {
            return [$issue->parentKey, $issue->parentSummary];
        }
        if (null !== $issue->epicLinkKey && '' !== $issue->epicLinkKey) {
            return [$issue->epicLinkKey, null];
        }

        return [null, null];
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, JiraIssue>
     */
    private function safeFindByKeys(ExternalConnection $connection, array $keys): array
    {
        try {
            return $this->issueService->findByKeys($connection, $keys);
        } catch (ExternalApiException $e) {
            // Erreurs d'authentification/réseau : on remonte. Le reste (droits partiels) est toléré.
            if (\in_array($e->getCategory(), [ExternalApiException::AUTHENTICATION, ExternalApiException::UNREACHABLE, ExternalApiException::TIMEOUT, ExternalApiException::CONFIGURATION], true)) {
                throw $e;
            }

            return [];
        }
    }
}
