<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote\Dto;

use App\Entity\JiraTicket;
use App\Entity\ReleaseSection;
use App\Service\Jira\Dto\EpicReference;
use App\Service\Jira\Dto\JiraIssue;
use App\Service\Jira\Dto\JiraVersion;

/**
 * Plan d'actualisation calculé avant application (présenté à l'utilisateur).
 */
final class SyncPlan
{
    public function __construct(public readonly JiraVersion $version)
    {
    }

    /** @var list<array{issue: JiraIssue, epic: ?EpicReference, section: ?ReleaseSection, plan: SectionPlan}> */
    public array $new = [];

    /** @var list<array{ticket: JiraTicket, issue: JiraIssue, epic: ?EpicReference, changes: list<string>}> */
    public array $updated = [];

    /** @var list<array{ticket: JiraTicket, issue: JiraIssue, epic: ?EpicReference}> */
    public array $unchanged = [];

    /** @var list<array{ticket: JiraTicket, issue: JiraIssue, epic: ?EpicReference}> tickets existants qui n'ont plus la version */
    public array $removedFromVersion = [];

    /** @var list<JiraTicket> tickets introuvables / sans droits */
    public array $inaccessible = [];

    /** @var list<array{ticket: JiraTicket, from: ?string, to: ?string}> Epic modifié dans Jira (classement conservé) */
    public array $epicChanges = [];

    /**
     * Tickets dont le regroupement (Epic, étiquette, type) a changé dans Jira.
     * automatic = ticket encore dans la section du regroupement automatique → déplacé ;
     * sinon classé à la main → laissé en place.
     *
     * @var list<array{ticket: JiraTicket, from: ?ReleaseSection, plan: SectionPlan, target: ?ReleaseSection, automatic: bool}>
     */
    public array $regroupings = [];

    /** @var array<string, SectionPlan> sections à créer, par clé de regroupement */
    public array $newSections = [];

    public function hasEditorialImpact(): bool
    {
        return [] !== $this->removedFromVersion || [] !== $this->inaccessible || [] !== $this->newSections || [] !== $this->regroupings;
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        return [
            'added' => \count($this->new),
            'updated' => \count($this->updated),
            'unchanged' => \count($this->unchanged),
            'removed' => \count($this->removedFromVersion),
            'inaccessible' => \count($this->inaccessible),
            'sections_created' => \count($this->newSections),
            'epic_changes' => \count($this->epicChanges),
            'regroupings' => \count(array_filter($this->regroupings, static fn (array $r) => $r['automatic'])),
        ];
    }
}
