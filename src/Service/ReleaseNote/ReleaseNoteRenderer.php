<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

use App\Entity\Release;
use App\Enum\PublicationFormat;
use App\Enum\PublicationTarget;
use App\Service\ReleaseNote\Dto\ReleaseNoteView;
use Twig\Environment;

/**
 * Rendu des trois formats :
 * - HTML web (aperçu, styles riches) : templates/render/web/<format>.html.twig ;
 * - format de stockage Confluence (XHTML + macros ac:*, compatible Cloud et Server/DC) :
 *   templates/render/confluence/<format>.xml.twig. Aucun CSS n'y est utilisé : la mise
 *   en forme repose sur les macros natives (panel, info, note, status, toc, expand).
 */
class ReleaseNoteRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ReleaseNoteViewBuilder $viewBuilder,
    ) {
    }

    public function buildView(Release $release, PublicationFormat $format): ReleaseNoteView
    {
        return $this->viewBuilder->build($release, $format);
    }

    public function renderWeb(Release $release, PublicationFormat $format): string
    {
        return $this->twig->render(sprintf('render/web/%s.html.twig', $format->value), $this->context($release, $format));
    }

    /** @return array<string, mixed> */
    private function context(Release $release, PublicationFormat $format): array
    {
        if (PublicationFormat::AcceptanceReport === $format) {
            $report = $release->getAcceptanceReport();
            if (null === $report) {
                throw new \RuntimeException('Aucun PV de recette n\'existe pour cette release.');
            }
            $anomalies = $report->getAnomalyItems();

            $done = array_values(array_filter($anomalies, static fn (array $a) => 'done' === ($a['statusCategory'] ?? null)));
            $open = array_values(array_filter($anomalies, static fn (array $a) => 'done' !== ($a['statusCategory'] ?? null)));

            return [
                'pv' => $report,
                'release' => $release,
                'anomalies_done' => \count($done),
                'anomalies_done_list' => $done,
                'anomalies_open_list' => $open,
                'anomalies_linked' => \count(array_filter($anomalies, static fn (array $a) => 'linked' === ($a['origin'] ?? 'version'))),
            ];
        }

        return ['doc' => $this->buildView($release, $format)];
    }

    public function renderStorage(Release $release, PublicationFormat $format): string
    {
        $xml = $this->twig->render(sprintf('render/confluence/%s.xml.twig', $format->value), $this->context($release, $format));
        // Compactage : supprime l'indentation des templates (retours à la ligne entre balises) en
        // conservant les espaces significatifs entre éléments en ligne (badges, coche + libellé…).
        $xml = (string) preg_replace('/>[ \t]*\R\s*</u', '><', trim($xml));

        $this->assertWellFormed($xml);

        return $xml;
    }

    /** Titre de page Confluence : stable (projet + version + format) pour éviter les doublons. */
    public function pageTitle(Release $release, PublicationFormat $format): string
    {
        $project = $release->getProject();

        return mb_substr(sprintf('%s %s — %s', $project?->getName() ?? '', $release->getJiraVersionName(), $format->pageSuffix()), 0, 255);
    }

    /** Titre de la page de regroupement : stable (projet + version) et distinct des pages de format. */
    public function containerTitle(Release $release): string
    {
        $project = $release->getProject();

        return mb_substr(sprintf('%s %s — Release notes', $project?->getName() ?? '', $release->getJiraVersionName()), 0, 255);
    }

    /** Contenu de la page de regroupement (liste automatique des pages enfants). */
    public function renderContainerStorage(Release $release, PublicationTarget $target): string
    {
        $project = $release->getProject();
        $xml = $this->twig->render('render/confluence/container.xml.twig', [
            'release' => $release,
            // Le PV n'est listé que s'il est rangé sous cette page (pas de page parente dédiée).
            'pv_in_container' => null !== $release->getAcceptanceReport() && null === $project?->getConfluenceAcceptanceParentPageId($target),
        ]);
        $xml = (string) preg_replace('/>[ \t]*\R\s*</u', '><', trim($xml));
        $this->assertWellFormed($xml);

        return $xml;
    }

    public function contentHash(string $title, string $storage): string
    {
        return hash('sha256', $title . "\n" . $storage);
    }

    /**
     * Vérifie que le contenu est un XHTML bien formé (sinon Confluence le rejetterait).
     *
     * @throws \RuntimeException
     */
    public function assertWellFormed(string $storage): void
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $ok = $document->loadXML(
            '<root xmlns:ac="http://atlassian.com/content" xmlns:ri="http://atlassian.com/resource/identifier">' . $storage . '</root>',
            LIBXML_NONET
        );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok || [] !== $errors) {
            $first = $errors[0] ?? null;
            throw new \RuntimeException(sprintf('Contenu Confluence mal formé%s.', null !== $first ? ' : ' . trim($first->message) : ''));
        }
    }
}
