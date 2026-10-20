<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Release;
use App\Enum\PublicationFormat;
use App\Form\AcceptanceReportType;
use App\Service\AcceptanceReport\AcceptanceReportService;
use App\Service\Http\ExternalApiException;
use App\Service\ReleaseNote\ReleaseNoteRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PV de recette d'une release (procès-verbal de tests système).
 * La publication passe par l'écran de publication commun (format « PV de recette »).
 */
#[Route('/releases/{id}/acceptance', requirements: ['id' => '\d+'])]
final class AcceptanceReportController extends AbstractAppController
{
    public function __construct(
        private readonly AcceptanceReportService $service,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'acceptance_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Release $release): Response
    {
        $report = $release->getAcceptanceReport();
        if (null === $report) {
            return $this->render('acceptance/create.html.twig', ['release' => $release]);
        }

        $form = $this->createForm(AcceptanceReportType::class, $report, ['disabled' => $release->isArchived()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && !$release->isArchived()) {
            $this->em->flush();
            $this->addFlash('success', 'PV de recette enregistré.');

            return $this->redirectToRoute('acceptance_edit', ['id' => $release->getId()]);
        }

        return $this->render('acceptance/edit.html.twig', ['release' => $release, 'report' => $report, 'form' => $form]);
    }

    #[Route('/create', name: 'acceptance_create', methods: ['POST'])]
    public function create(Request $request, Release $release): Response
    {
        $this->assertCsrf($request, 'acceptance-create-' . $release->getId());
        if ($release->isArchived()) {
            $this->addFlash('warning', 'Release archivée : désarchivez-la pour créer le PV.');

            return $this->redirectToRoute('release_show', ['id' => $release->getId()]);
        }
        $report = $this->service->create($release);
        try {
            $counts = $this->service->refreshFromJira($report);
            $this->addFlash('success', sprintf('PV créé. Jira : %d fiche(s) dans le périmètre, %d avec des commentaires de tests, %d anomalie(s).', $counts['scope'], $counts['tests'], $counts['anomalies']));
        } catch (ExternalApiException $e) {
            $this->addFlash('warning', 'PV créé, mais lecture Jira impossible : ' . $e->getMessage());
        }

        return $this->redirectToRoute('acceptance_edit', ['id' => $release->getId()]);
    }

    #[Route('/refresh', name: 'acceptance_refresh', methods: ['POST'])]
    public function refresh(Request $request, Release $release): Response
    {
        $this->assertCsrf($request, 'acceptance-refresh-' . $release->getId());
        $report = $release->getAcceptanceReport();
        if (null === $report || $release->isArchived()) {
            return $this->redirectToRoute('acceptance_edit', ['id' => $release->getId()]);
        }
        try {
            $counts = $this->service->refreshFromJira($report);
            $this->addFlash('success', sprintf('Données Jira actualisées : %d fiche(s) dans le périmètre, %d avec des commentaires de tests, %d anomalie(s). Les textes saisis n\'ont pas été modifiés.', $counts['scope'], $counts['tests'], $counts['anomalies']));
        } catch (ExternalApiException $e) {
            $this->addFlash('danger', 'Actualisation impossible : ' . $e->getMessage());
        }

        return $this->redirectToRoute('acceptance_edit', ['id' => $release->getId()]);
    }

    #[Route('/preview', name: 'acceptance_preview', methods: ['GET'])]
    public function preview(Release $release, ReleaseNoteRenderer $renderer): Response
    {
        if (null === $release->getAcceptanceReport()) {
            return $this->redirectToRoute('acceptance_edit', ['id' => $release->getId()]);
        }

        return $this->render('acceptance/preview.html.twig', [
            'release' => $release,
            'html' => $renderer->renderWeb($release, PublicationFormat::AcceptanceReport),
            'storage' => null,
            'page_title' => $renderer->pageTitle($release, PublicationFormat::AcceptanceReport),
        ]);
    }

    #[Route('/preview/storage', name: 'acceptance_preview_storage', methods: ['GET'])]
    public function previewStorage(Release $release, ReleaseNoteRenderer $renderer): Response
    {
        if (null === $release->getAcceptanceReport()) {
            return $this->redirectToRoute('acceptance_edit', ['id' => $release->getId()]);
        }

        return $this->render('acceptance/preview.html.twig', [
            'release' => $release,
            'html' => null,
            'storage' => $renderer->renderStorage($release, PublicationFormat::AcceptanceReport),
            'page_title' => $renderer->pageTitle($release, PublicationFormat::AcceptanceReport),
        ]);
    }

    #[Route('/delete', name: 'acceptance_delete', methods: ['POST'])]
    public function delete(Request $request, Release $release): Response
    {
        $this->assertCsrf($request, 'acceptance-delete-' . $release->getId());
        $report = $release->getAcceptanceReport();
        if (null !== $report && !$release->isArchived()) {
            $release->setAcceptanceReport(null);
            $this->em->remove($report);
            foreach ($release->getPublications()->toArray() as $publication) {
                if (PublicationFormat::AcceptanceReport === $publication->getFormat()) {
                    $release->getPublications()->removeElement($publication);
                    $this->em->remove($publication);
                }
            }
            $this->em->flush();
            $this->addFlash('success', 'PV de recette supprimé. Une page déjà publiée dans Confluence n\'est pas supprimée.');
        }

        return $this->redirectToRoute('release_show', ['id' => $release->getId()]);
    }
}
