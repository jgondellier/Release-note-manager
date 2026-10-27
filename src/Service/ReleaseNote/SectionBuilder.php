<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

use App\Entity\JiraTicket;
use App\Enum\SectionCategory;
use App\Enum\SectionSourceType;
use App\Service\Jira\Dto\EpicReference;
use App\Service\Jira\Dto\JiraIssue;
use App\Service\ReleaseNote\Dto\IssueTypeRuleSet;
use App\Service\ReleaseNote\Dto\SectionPlan;

/**
 * Regroupement automatique initial des tickets :
 * - une section par Epic (titre initial = titre de l'Epic) ;
 * - sinon, une section par étiquette Jira (titre = phrase de la table de conversion,
 *   à défaut l'étiquette elle-même ; étiquettes « ignorées » exclues) ;
 * - sinon, regroupement selon les correspondances de types.
 *
 * Classe sans état ni dépendance : directement testable.
 */
final class SectionBuilder
{
    /**
     * @param list<JiraIssue>                   $issues
     * @param array<string, EpicReference|null> $epics
     *
     * @return list<SectionPlan> sections ordonnées : Epics (par titre), étiquettes (ordre de la table puis titre), types (par position)
     */
    public function build(array $issues, array $epics, IssueTypeRuleSet $rules): array
    {
        /** @var array<string, SectionPlan> $epicPlans */
        $epicPlans = [];
        /** @var array<string, SectionPlan> $typePlans */
        $typePlans = [];
        /** @var array<string, SectionPlan> $labelPlans */
        $labelPlans = [];
        /** @var array<string, array<string, int>> $labelCategoryVotes */
        $labelCategoryVotes = [];
        /** @var array<string, array<string, int>> $epicCategoryVotes */
        $epicCategoryVotes = [];

        foreach ($issues as $issue) {
            $epic = $epics[$issue->key] ?? null;
            $rule = $rules->match($issue->issueType);

            if (null !== $epic) {
                $epicPlans[$epic->key] ??= new SectionPlan(SectionSourceType::Epic, $epic->key, $epic->name, SectionCategory::Feature, 0);
                $epicPlans[$epic->key]->issueKeys[] = $issue->key;
                if (!$issue->isEpic) {
                    $epicCategoryVotes[$epic->key][$rule->category->value] = ($epicCategoryVotes[$epic->key][$rule->category->value] ?? 0) + 1;
                }
                continue;
            }

            $label = $rules->labels()->resolve($issue->labels);
            if (null !== $label) {
                $labelPlans[$label['key']] ??= new SectionPlan(SectionSourceType::Label, $label['key'], $label['title'], $label['category'] ?? SectionCategory::Feature, $label['position']);
                $labelPlans[$label['key']]->issueKeys[] = $issue->key;
                if (null === $label['category']) {
                    $labelCategoryVotes[$label['key']][$rule->category->value] = ($labelCategoryVotes[$label['key']][$rule->category->value] ?? 0) + 1;
                }
                continue;
            }

            // Une section par titre : plusieurs types peuvent partager « Corrections ».
            $groupKey = mb_strtolower($rule->sectionTitle);
            $typePlans[$groupKey] ??= new SectionPlan(SectionSourceType::IssueType, $rule->sectionTitle, $rule->sectionTitle, $rule->category, $rule->position);
            $typePlans[$groupKey]->issueKeys[] = $issue->key;
        }

        // Catégorie d'une section d'Epic = catégorie majoritaire de ses tickets (Évolution par défaut).
        foreach ($epicPlans as $key => $plan) {
            $votes = $epicCategoryVotes[$key] ?? [];
            if ([] !== $votes) {
                arsort($votes);
                $plan->category = SectionCategory::from((string) array_key_first($votes));
            }
            // L'Epic lui-même en tête de section, puis les autres tickets par clé.
            usort($plan->issueKeys, static fn (string $a, string $b) => ($a === $key ? -1 : ($b === $key ? 1 : strnatcmp($a, $b))));
        }

        // Étiquette sans catégorie imposée : catégorie majoritaire de ses tickets.
        foreach ($labelPlans as $key => $plan) {
            $votes = $labelCategoryVotes[$key] ?? [];
            if ([] !== $votes) {
                arsort($votes);
                $plan->category = SectionCategory::from((string) array_key_first($votes));
            }
            usort($plan->issueKeys, 'strnatcmp');
        }
        $labelList = array_values($labelPlans);
        usort($labelList, static fn (SectionPlan $a, SectionPlan $b) => $a->sortWeight <=> $b->sortWeight ?: strnatcasecmp($a->title, $b->title));

        $epicList = array_values($epicPlans);
        usort($epicList, static fn (SectionPlan $a, SectionPlan $b) => strnatcasecmp($a->title, $b->title));

        $typeList = array_values($typePlans);
        usort($typeList, static fn (SectionPlan $a, SectionPlan $b) => [$a->sortWeight, $a->title] <=> [$b->sortWeight, $b->title]);
        foreach ($typeList as $plan) {
            usort($plan->issueKeys, 'strnatcmp');
        }

        return [...$epicList, ...$labelList, ...$typeList];
    }

    /**
     * Section cible d'un ticket isolé (actualisation / ajout manuel).
     */
    public function planFor(JiraIssue $issue, ?EpicReference $epic, IssueTypeRuleSet $rules): SectionPlan
    {
        return $this->planForValues($issue->issueType, $issue->labels, $epic, $rules);
    }

    /**
     * Section où le regroupement automatique aurait placé un ticket déjà importé, d'après ses données
     * connues avant actualisation.
     */
    public function planForTicket(JiraTicket $ticket, IssueTypeRuleSet $rules): SectionPlan
    {
        $epicKey = $ticket->getEpicKey();
        $epic = null === $epicKey ? null : new EpicReference($epicKey, $ticket->getEpicName() ?? $epicKey);

        return $this->planForValues($ticket->getIssueType(), $ticket->getLabels(), $epic, $rules);
    }

    /** @param list<string> $labels */
    private function planForValues(string $issueType, array $labels, ?EpicReference $epic, IssueTypeRuleSet $rules): SectionPlan
    {
        if (null !== $epic) {
            return new SectionPlan(SectionSourceType::Epic, $epic->key, $epic->name, $rules->match($issueType)->category, 0);
        }
        $rule = $rules->match($issueType);
        $label = $rules->labels()->resolve($labels);
        if (null !== $label) {
            return new SectionPlan(SectionSourceType::Label, $label['key'], $label['title'], $label['category'] ?? $rule->category, $label['position']);
        }

        return new SectionPlan(SectionSourceType::IssueType, $rule->sectionTitle, $rule->sectionTitle, $rule->category, $rule->position);
    }
}
