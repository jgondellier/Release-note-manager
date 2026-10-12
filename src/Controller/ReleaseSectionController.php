<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Release;
use App\Entity\ReleaseSection;
use App\Enum\SectionCategory;
use App\Form\ReleaseSectionType;
use App\Service\ReleaseNote\ReleaseSectionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReleaseSectionController extends AbstractAppController
{
    public function __construct(private readonly ReleaseSectionService $sectionService)
    {
    }

    #[Route('/releases/{id}/sections/new', name: 'section_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function new(Request $request, Release $release): Response
    {
        if ($release->isArchived()) {
            return $this->archivedRedirect($release);
        }
        $section = (new ReleaseSection())->setCategory(SectionCategory::Feature);
        $form = $this->createForm(ReleaseSectionType::class, $section);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->sectionService->createManualSection($release, $section);
            $this->addFlash('success', sprintf('Section « %s » ajoutée.', $section->getTitle()));

            return $this->redirectToRoute('release_show', ['id' => $release->getId(), '_fragment' => 'section-' . $section->getId()]);
        }

        return $this->render('release_section/form.html.twig', ['release' => $release, 'section' => $section, 'form' => $form, 'is_new' => true]);
    }

    #[Route('/sections/{id}/edit', name: 'section_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, ReleaseSection $section): Response
    {
        $release = $section->getRelease();
        \assert(null !== $release);
        if ($release->isArchived()) {
            return $this->archivedRedirect($release);
        }
        $form = $this->createForm(ReleaseSectionType::class, $section);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->sectionService->save($section);
            $this->addFlash('success', 'Section enregistrée.');

            if ($request->getPayload()->getBoolean('stay')) {
                return $this->redirectToRoute('section_edit', ['id' => $section->getId()]);
            }

            return $this->redirectToRoute('release_show', ['id' => $release->getId(), '_fragment' => 'section-' . $section->getId()]);
        }

        return $this->render('release_section/form.html.twig', ['release' => $release, 'section' => $section, 'form' => $form, 'is_new' => false]);
    }

    #[Route('/sections/{id}/delete', name: 'section_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, ReleaseSection $section): Response
    {
        $this->assertCsrf($request, 'section-delete-' . $section->getId());
        $release = $section->getRelease();
        \assert(null !== $release);
        $title = $section->getTitle();
        try {
            $moved = $this->sectionService->deleteSection($section);
            $this->addFlash('success', sprintf('Section « %s » supprimée. %d ticket(s) déplacé(s) dans « Non classés ».', $title, $moved));
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('release_show', ['id' => $release->getId()]);
    }

    #[Route('/sections/{id}/move/{direction}', name: 'section_move', requirements: ['id' => '\d+', 'direction' => 'up|down'], methods: ['POST'])]
    public function move(Request $request, ReleaseSection $section, string $direction): Response
    {
        $this->assertCsrf($request, 'section-move-' . $section->getId());
        try {
            $this->sectionService->moveSection($section, $direction);
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('release_show', ['id' => $section->getRelease()?->getId(), '_fragment' => 'section-' . $section->getId()]);
    }

    /** Réordonnancement complet (glisser-déposer). Corps : _token + order[] (identifiants). */
    #[Route('/releases/{id}/sections/reorder', name: 'section_reorder', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reorder(Request $request, Release $release): Response
    {
        $this->assertCsrf($request, 'section-reorder-' . $release->getId());
        $ids = array_values(array_filter(array_map('intval', (array) $request->getPayload()->all('order'))));
        try {
            $this->sectionService->reorderSections($release, $ids);
        } catch (\LogicException $e) {
            return $request->isXmlHttpRequest()
                ? new JsonResponse(['ok' => false, 'message' => $e->getMessage()], Response::HTTP_CONFLICT)
                : $this->archivedRedirect($release);
        }

        return $request->isXmlHttpRequest()
            ? new JsonResponse(['ok' => true])
            : $this->redirectToRoute('release_show', ['id' => $release->getId()]);
    }

    private function archivedRedirect(Release $release): Response
    {
        $this->addFlash('warning', 'Release archivée : désarchivez-la pour la modifier.');

        return $this->redirectToRoute('release_show', ['id' => $release->getId()]);
    }
}
