<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Jira\OctaneCoverageService;
use App\Service\ReleaseNote\Design\CommunicationTheme;
use App\Service\ReleaseNote\Email\EmailHtmlStyler;
use App\Service\ReleaseNote\LinkListParser;
use App\Service\ReleaseNote\TextFormatter;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly TextFormatter $formatter,
        private readonly LinkListParser $linkParser,
        private readonly EmailHtmlStyler $emailStyler,
    ) {
    }

    public function getFilters(): array
    {
        return [
            // Texte éditorial → XHTML sûr (échappement intégral puis syntaxe légère).
            new TwigFilter('rn_text', $this->formatter->toHtml(...), ['is_safe' => ['html']]),
            new TwigFilter('rn_inline', $this->formatter->inline(...), ['is_safe' => ['html']]),
            // Styles en ligne pour les e-mails (après rn_text / rn_inline ; toute autre entrée est échappée).
            // E-mails : texte éditorial brut → XHTML sûr avec styles en ligne.
            new TwigFilter('rn_email_text', fn (?string $text): string => $this->emailStyler->style($this->formatter->toHtml($text)), ['is_safe' => ['html']]),
            // Bloc HTML capturé par {% set %} (objet Markup) : stylé tel quel ; toute autre valeur est échappée.
            new TwigFilter('rn_email', fn (mixed $value): string => $this->emailStyler->style(
                $value instanceof Markup ? (string) $value : htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            ), ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('rn_links', $this->linkParser->parse(...)),
            // Charte graphique du format Communication (jetons partagés web / Confluence).
            new TwigFunction('rn_theme', CommunicationTheme::tokens(...)),
            new TwigFunction('rn_theme_css', CommunicationTheme::cssVariables(...)),
            // {% do rn_ticket_links(release) %} : numéros de ticket des textes de la page liés vers Jira.
            new TwigFunction('rn_ticket_links', $this->formatter->linkTicketsOf(...)),
            // Couverture Octane : code couleur d'une fiche et lignes du résumé.
            new TwigFunction('rn_coverage_health', OctaneCoverageService::health(...)),
            new TwigFunction('rn_coverage_levels', static fn () => OctaneCoverageService::HEALTH_LEVELS),
            new TwigFunction('rn_coverage_rows', OctaneCoverageService::summaryRows(...)),
        ];
    }
}
