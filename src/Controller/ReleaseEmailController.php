<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Release;
use App\Enum\PublicationFormat;
use App\Enum\PublicationTarget;
use App\Service\ReleaseNote\Email\EmlBuilder;
use App\Service\ReleaseNote\ReleaseNoteRenderer;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Release note par e-mail : aperçu, copier-coller (HTML riche), brouillon Outlook (.eml) et lien mailto (texte).
 */
#[Route('/releases/{id}/email/{format}', requirements: ['id' => '\d+', 'format' => 'it|functional|communication'])]
final class ReleaseEmailController extends AbstractAppController
{
    /** Longueur maximale du corps texte d'un lien mailto (limite des navigateurs et d'Outlook). */
    private const MAILTO_BODY_MAX = 1500;

    public function __construct(
        private readonly ReleaseNoteRenderer $renderer,
        private readonly EmlBuilder $emlBuilder,
    ) {
    }

    #[Route('', name: 'release_email', methods: ['GET'])]
    public function show(Request $request, Release $release, string $format): Response
    {
        $publicationFormat = PublicationFormat::from($format);
        [$to, $invalidTo] = EmlBuilder::parseAddresses($request->query->getString('to'));
        [$cc, $invalidCc] = EmlBuilder::parseAddresses($request->query->getString('cc'));
        $subject = $this->renderer->pageTitle($release, $publicationFormat);
        $html = $this->renderer->renderEmail($release, $publicationFormat, $this->publishedUrl($release, $publicationFormat));

        $text = EmlBuilder::toText($html);
        $truncated = mb_strlen($text) > self::MAILTO_BODY_MAX;
        if ($truncated) {
            $text = rtrim(mb_substr($text, 0, self::MAILTO_BODY_MAX)) . "\n\n[…] Version complète : voir le message mis en forme ou Confluence.";
        }
        // « @ » laissé en clair dans les adresses : certaines messageries ne décodent pas %40.
        $address = static fn (array $list) => str_replace('%40', '@', implode(',', array_map('rawurlencode', $list)));
        $mailto = 'mailto:' . $address($to) . '?' . http_build_query(array_filter([
            'cc' => implode(',', $cc),
            'subject' => $subject,
            'body' => $text,
        ]), '', '&', PHP_QUERY_RFC3986);

        return $this->render('release/email.html.twig', [
            'release' => $release,
            'format' => $publicationFormat,
            'formats' => PublicationFormat::releaseNoteFormats(),
            'subject' => $subject,
            'html' => $html,
            'to' => implode(', ', $to),
            'cc' => implode(', ', $cc),
            'invalid' => [...$invalidTo, ...$invalidCc],
            'mailto' => $mailto,
            'mailto_truncated' => $truncated,
        ]);
    }

    #[Route('/eml', name: 'release_email_eml', methods: ['GET'])]
    public function eml(Request $request, Release $release, string $format): Response
    {
        $publicationFormat = PublicationFormat::from($format);
        [$to] = EmlBuilder::parseAddresses($request->query->getString('to'));
        [$cc] = EmlBuilder::parseAddresses($request->query->getString('cc'));
        $subject = $this->renderer->pageTitle($release, $publicationFormat);
        $eml = $this->emlBuilder->build($subject, $this->renderer->renderEmail($release, $publicationFormat, $this->publishedUrl($release, $publicationFormat)), $to, $cc);

        // Nom en ASCII seul (accents retirés, « — » → « - ») : certains navigateurs ignorent la variante UTF-8 (filename*).
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', str_replace(['—', '–'], '-', $subject));
        $filename = trim((string) preg_replace(['/[^A-Za-z0-9._ -]+/', '/\s+/'], ['', ' '], $ascii), ' .-') ?: 'release-note';
        $filename .= '.eml';

        return new Response($eml, Response::HTTP_OK, [
            'Content-Type' => 'message/rfc822',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Page Confluence publiée pour ce format (Cloud de préférence), pour le lien « version complète ». */
    private function publishedUrl(Release $release, PublicationFormat $format): ?string
    {
        foreach (PublicationTarget::cases() as $target) {
            $url = $release->findPublication($format, $target)?->getConfluencePageUrl();
            if (null !== $url && '' !== $url) {
                return $url;
            }
        }

        return null;
    }
}
