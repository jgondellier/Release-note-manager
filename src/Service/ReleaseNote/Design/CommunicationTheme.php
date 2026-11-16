<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote\Design;

use App\Enum\SectionCategory;

/**
 * Charte graphique de la release note « Communication » : source unique des jetons de design
 * (couleurs de marque, neutres, catégories, états), utilisée par l'aperçu web (variables CSS --ds-*)
 * et par le rendu Confluence (couleurs des macros panel/status).
 *
 * Pour appliquer la charte de l'entreprise, modifier uniquement les valeurs ci-dessous.
 * Contrastes vérifiés : texte sur fond « soft » et blanc sur « strong » ≥ 4,5:1 (WCAG AA).
 */
final class CommunicationTheme
{
    /** Marque et neutres. */
    public const BRAND = [
        'primary' => '#0B5CAD',
        'primaryStrong' => '#0A2F5C',
        'primarySoft' => '#E8F1FB',
        'text' => '#1B2738',
        'muted' => '#556378',
        'border' => '#DCE3EC',
        'surface' => '#FFFFFF',
        'surfaceAlt' => '#F5F7FA',
    ];

    /** Catégories de section : accent (texte, bordures), fond doux, libellé public, pictogramme, lozenge Confluence. */
    public const CATEGORIES = [
        'feature' => ['accent' => '#0B5CAD', 'soft' => '#E8F1FB', 'label' => 'Nouveauté', 'icon' => '✦', 'status' => 'Blue'],
        'other' => ['accent' => '#5B3FB0', 'soft' => '#F0ECFA', 'label' => 'Amélioration', 'icon' => '▲', 'status' => 'Purple'],
        'technical' => ['accent' => '#4A5568', 'soft' => '#F1F3F6', 'label' => 'Technique', 'icon' => '⚙', 'status' => 'Grey'],
        'fix' => ['accent' => '#17663F', 'soft' => '#E7F5EE', 'label' => 'Correction', 'icon' => '✔', 'status' => 'Green'],
    ];

    /** États (encadrés). */
    public const STATES = [
        'action' => ['accent' => '#9A4D00', 'soft' => '#FFF4E5'],
        'pending' => ['accent' => '#4A5568', 'soft' => '#F1F3F6'],
    ];

    /** @return array{brand: array<string, string>, categories: array<string, array<string, string>>, states: array<string, array<string, string>>} */
    public static function tokens(): array
    {
        return ['brand' => self::BRAND, 'categories' => self::CATEGORIES, 'states' => self::STATES];
    }

    /** @return array<string, string> */
    public static function category(SectionCategory $category): array
    {
        return self::CATEGORIES[$category->value];
    }

    /** Variables CSS --ds-* à poser sur la racine du document web (une seule source de vérité). */
    public static function cssVariables(): string
    {
        $vars = [];
        foreach (self::BRAND as $name => $value) {
            $vars[] = sprintf('--ds-%s: %s', self::kebab($name), $value);
        }
        foreach ([...self::CATEGORIES, ...self::STATES] as $name => $set) {
            $vars[] = sprintf('--ds-%s: %s', $name, $set['accent']);
            $vars[] = sprintf('--ds-%s-soft: %s', $name, $set['soft']);
        }

        return implode('; ', $vars);
    }

    private static function kebab(string $name): string
    {
        return strtolower((string) preg_replace('/[A-Z]/', '-$0', $name));
    }
}
