<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

use App\Entity\JiraTicket;
use App\Enum\TicketSyncState;
use App\Service\Jira\Dto\EpicReference;
use App\Service\Jira\Dto\JiraIssue;

/**
 * Recopie les métadonnées Jira dans un JiraTicket. Ne touche JAMAIS à la section,
 * à la position, à l'exclusion ni à aucun contenu éditorial.
 */
final class JiraTicketMapper
{
    /**
     * @return list<string> libellés des champs modifiés (vide si inchangé)
     */
    public function apply(JiraTicket $ticket, JiraIssue $issue, ?EpicReference $epic): array
    {
        $changes = [];
        $set = function (string $label, mixed $current, mixed $new, callable $setter) use (&$changes): void {
            if ($current !== $new) {
                $changes[] = $label;
                $setter($new);
            }
        };

        $set('clé', $ticket->getJiraKey(), $issue->key, $ticket->setJiraKey(...));
        $set('identifiant', $ticket->getJiraIssueId(), $issue->id, $ticket->setJiraIssueId(...));
        $set('type', $ticket->getIssueType(), mb_substr($issue->issueType, 0, 100), $ticket->setIssueType(...));
        $set('résumé', $ticket->getSummary(), mb_substr($issue->summary, 0, 500), $ticket->setSummary(...));
        $description = null === $issue->description ? null : mb_substr($issue->description, 0, JiraTicket::MAX_DESCRIPTION_LENGTH);
        $set('description', $ticket->getDescription(), $description, $ticket->setDescription(...));
        $set('statut', $ticket->getStatus(), $issue->status, $ticket->setStatus(...));
        $set('catégorie de statut', $ticket->getStatusCategory(), $issue->statusCategory, $ticket->setStatusCategory(...));
        $set('priorité', $ticket->getPriority(), $issue->priority, $ticket->setPriority(...));
        $set('Epic', $ticket->getEpicKey(), $epic?->key, $ticket->setEpicKey(...));
        $epicName = null === $epic ? null : mb_substr($epic->name, 0, 255);
        $set('titre d\'Epic', $ticket->getEpicName(), $epicName, $ticket->setEpicName(...));
        $set('parent', $ticket->getParentKey(), $issue->parentKey, $ticket->setParentKey(...));
        $set('composants', $ticket->getComponents(), $issue->components, $ticket->setComponents(...));
        $set('labels', $ticket->getLabels(), $issue->labels, $ticket->setLabels(...));
        $set('Fix Versions', $ticket->getFixVersions(), $issue->fixVersions, $ticket->setFixVersions(...));
        $set('URL', $ticket->getJiraUrl(), $issue->url, $ticket->setJiraUrl(...));

        $ticket->setRawData($issue->diagnostic);
        $ticket->setJiraUpdatedAt($issue->updatedAt);
        $ticket->setLastSyncedAt(new \DateTimeImmutable());
        if (TicketSyncState::Active !== $ticket->getSyncState()) {
            $changes[] = 'retour dans le périmètre';
        }
        $ticket->setSyncState(TicketSyncState::Active);

        return $changes;
    }
}
