<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Jira\OctaneCoverageService;
use App\Service\ReleaseNote\LinkListParser;
use App\Service\ReleaseNote\TextFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly TextFormatter $formatter,
        private readonly LinkListParser $linkParser,
    ) {
    }

    public function getFilters(): array
    {
        return [
            // Texte éditorial → XHTML sûr (échappement intégral puis syntaxe légère).
            new TwigFilter('rn_text', $this->formatter->toHtml(...), ['is_safe' => ['html']]),
            new TwigFilter('rn_inline', $this->formatter->inline(...), ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('rn_links', $this->linkParser->parse(...)),
            // Couverture Octane : code couleur d'une fiche et lignes du résumé.
            new TwigFunction('rn_coverage_health', OctaneCoverageService::health(...)),
            new TwigFunction('rn_coverage_levels', static fn () => OctaneCoverageService::HEALTH_LEVELS),
            new TwigFunction('rn_coverage_rows', OctaneCoverageService::summaryRows(...)),
        ];
    }
}
