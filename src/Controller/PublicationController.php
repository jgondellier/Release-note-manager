<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Publication;
use App\Entity\Release;
use App\Repository\PublicationAttemptRepository;
use App\Service\Publication\PublicationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicationController extends AbstractAppController
{
    public function __construct(private readonly PublicationService $publicationService)
    {
    }

    /** Sélection des cibles (formats × instances). */
    #[Route('/releases/{id}/publish', name: 'publication_select', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function select(Release $release): Response
    {
        return $this->render('publication/select.html.twig', [
            'release' => $release,
            'rows' => $this->publicationService->getTargetMatrix($release),
        ]);
    }

    /** Écran de confirmation : récapitulatif précis de chaque cible sélectionnée. */
    #[Route('/releases/{id}/publish/confirm', name: 'publication_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirm(Request $request, Release $release): Response
    {
        $this->assertCsrf($request, 'publication-select-' . $release->getId());
        $selected = array_map('strval', (array) $request->getPayload()->all('targets'));
        $rows = array_values(array_filter(
            $this->publicationService->getTargetMatrix($release),
            static fn ($row) => $row->available && \in_array($row->key(), $selected, true)
        ));
        if ([] === $rows) {
            $this->addFlash('warning', 'Sélectionnez au moins une cible disponible.');

            return $this->redirectToRoute('publication_select', ['id' => $release->getId()]);
        }

        return $this->render('publication/confirm.html.twig', ['release' => $release, 'rows' => $rows]);
    }

    /** Exécution : chaque cible est publiée indépendamment. */
    #[Route('/releases/{id}/publish/execute', name: 'publication_execute', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function execute(Request $request, Release $release): Response
    {
        $this->assertCsrf($request, 'publication-execute-' . $release->getId());
        $targets = array_map('strval', (array) $request->getPayload()->all('targets'));
        $outcomes = $this->publicationService->publish($release, $targets);

        return $this->render('publication/result.html.twig', ['release' => $release, 'outcomes' => $outcomes]);
    }

    #[Route('/publications/{id}/retry', name: 'publication_retry', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retry(Request $request, Publication $publication): Response
    {
        $this->assertCsrf($request, 'publication-retry-' . $publication->getId());
        $outcome = $this->publicationService->retry($publication);
        $this->addFlash($outcome->isSuccess() ? 'success' : 'danger', sprintf('%s → %s : %s', $outcome->format->label(), $outcome->target->label(), $outcome->message));

        return $this->redirectToRoute('publication_history', ['id' => $publication->getRelease()?->getId()]);
    }

    #[Route('/releases/{id}/publications', name: 'publication_history', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function history(Release $release, PublicationAttemptRepository $attempts): Response
    {
        return $this->render('publication/history.html.twig', [
            'release' => $release,
            'rows' => $this->publicationService->getTargetMatrix($release),
            'attempts' => $attempts->findForRelease($release),
        ]);
    }
}
