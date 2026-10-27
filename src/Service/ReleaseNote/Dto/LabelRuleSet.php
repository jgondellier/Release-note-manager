<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote\Dto;

use App\Entity\LabelMapping;
use App\Enum\SectionCategory;

/**
 * Règles de regroupement par étiquette (table de conversion mot-clé → phrase).
 */
final class LabelRuleSet
{
    /** @var array<string, array{label: string, title: ?string, category: ?SectionCategory, ignored: bool, position: int}> */
    private array $rules = [];

    /** @param list<LabelMapping> $mappings */
    public static function fromMappings(array $mappings): self
    {
        $set = new self();
        // Règles globales d'abord, remplacées par celles du projet.
        usort($mappings, static fn (LabelMapping $a, LabelMapping $b) => (null === $a->getProject() ? 0 : 1) <=> (null === $b->getProject() ? 0 : 1));
        foreach ($mappings as $m) {
            $set->add($m->getLabel(), $m->getTitle(), $m->getCategory(), $m->isIgnored(), $m->getPosition());
        }

        return $set;
    }

    public function add(string $label, ?string $title, ?SectionCategory $category = null, bool $ignored = false, int $position = 100): self
    {
        $this->rules[self::key($label)] = ['label' => $label, 'title' => $title, 'category' => $category, 'ignored' => $ignored, 'position' => $position];

        return $this;
    }

    /**
     * Étiquette retenue pour regrouper un ticket : d'abord une étiquette convertie (ordre de la
     * table), sinon la première étiquette non ignorée (ordre alphabétique) ; null si aucune.
     *
     * @param list<string> $labels
     *
     * @return array{key: string, title: string, category: ?SectionCategory, position: int}|null
     */
    public function resolve(array $labels): ?array
    {
        $candidates = [];
        foreach ($labels as $label) {
            $label = trim($label);
            if ('' === $label) {
                continue;
            }
            $rule = $this->rules[self::key($label)] ?? null;
            if (null !== $rule && $rule['ignored']) {
                continue;
            }
            $candidates[] = [
                'key' => self::key($label),
                'title' => $rule['title'] ?? $label,
                'category' => $rule['category'] ?? null,
                'position' => null !== $rule ? $rule['position'] : PHP_INT_MAX,
                'mapped' => null !== $rule,
                'label' => $label,
            ];
        }
        if ([] === $candidates) {
            return null;
        }
        usort($candidates, static fn (array $a, array $b) => [$a['mapped'] ? 0 : 1, $a['position']] <=> [$b['mapped'] ? 0 : 1, $b['position']]
            ?: strnatcasecmp($a['label'], $b['label']));
        $best = $candidates[0];

        return ['key' => $best['key'], 'title' => $best['title'], 'category' => $best['category'], 'position' => $best['position']];
    }

    public static function key(string $label): string
    {
        return mb_strtolower(trim($label));
    }
}
