<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote\Dto;

use App\Entity\IssueTypeMapping;
use App\Enum\SectionCategory;

/**
 * Règles « type Jira → section » applicables à un projet.
 * Priorité : règle du projet > règle globale > défaut du projet (*) > défaut global (*) > repli codé.
 */
final class IssueTypeRuleSet
{
    public const FALLBACK_TITLE = 'Autres changements';

    /** @var array<string, IssueTypeRule> */
    private array $rules = [];
    private ?IssueTypeRule $default = null;

    /** @param list<IssueTypeMapping> $mappings */
    public static function fromMappings(array $mappings): self
    {
        $set = new self();
        // Les règles globales d'abord, puis celles du projet qui les remplacent.
        usort($mappings, static fn (IssueTypeMapping $a, IssueTypeMapping $b) => (null === $a->getProject() ? 0 : 1) <=> (null === $b->getProject() ? 0 : 1));
        foreach ($mappings as $mapping) {
            $set->add($mapping->getJiraIssueType(), new IssueTypeRule($mapping->getSectionTitle(), $mapping->getCategory(), $mapping->getPosition()));
        }

        return $set;
    }

    public function add(string $issueType, IssueTypeRule $rule): self
    {
        if (IssueTypeMapping::DEFAULT_TYPE === $issueType) {
            $this->default = $rule;
        } else {
            $this->rules[self::normalize($issueType)] = $rule;
        }

        return $this;
    }

    public function match(string $issueType): IssueTypeRule
    {
        return $this->rules[self::normalize($issueType)]
            ?? $this->default
            ?? new IssueTypeRule(self::FALLBACK_TITLE, SectionCategory::Other, 999);
    }

    private static function normalize(string $type): string
    {
        return mb_strtolower(trim($type));
    }
}
