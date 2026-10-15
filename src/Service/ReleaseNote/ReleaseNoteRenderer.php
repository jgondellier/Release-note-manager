<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

use App\Entity\Release;
use App\Enum\PublicationFormat;
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
        return $this->twig->render(sprintf('render/web/%s.html.twig', $format->value), [
            'doc' => $this->buildView($release, $format),
        ]);
    }

    public function renderStorage(Release $release, PublicationFormat $format): string
    {
        $xml = $this->twig->render(sprintf('render/confluence/%s.xml.twig', $format->value), [
            'doc' => $this->buildView($release, $format),
        ]);
        // Compactage : supprime l'indentation des templates (sans effet sur le rendu Confluence).
        $xml = (string) preg_replace('/>\s+</u', '><', trim($xml));

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
    public function renderContainerStorage(Release $release): string
    {
        $xml = $this->twig->render('render/confluence/container.xml.twig', ['release' => $release]);
        $xml = (string) preg_replace('/>\s+</u', '><', trim($xml));
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
