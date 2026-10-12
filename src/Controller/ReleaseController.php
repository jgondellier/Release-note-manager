<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Release;
use App\Enum\PublicationFormat;
use App\Enum\ReleaseStatus;
use App\Form\ReleaseMetadataType;
use App\Repository\ProjectRepository;
use App\Repository\ReleaseRepository;
use App\Service\Http\ExternalApiException;
use App\Service\Jira\JiraVersionService;
use App\Service\ReleaseNote\ReleaseAlreadyExistsException;
use App\Service\ReleaseNote\ReleaseDuplicationService;
use App\Service\ReleaseNote\ReleaseImportService;
use App\Service\ReleaseNote\ReleaseRefreshService;
use App\Service\ReleaseNote\ReleaseNoteRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/releases')]
final class ReleaseController extends AbstractAppController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'release_index', methods: ['GET'])]
    public function index(Request $request, ReleaseRepository $releases, ProjectRepository $projects): Response
    {
        $project = null;
        if ($projectId = $request->query->getInt('project')) {
            $project = $projects->find($projectId);
        }
        $status = ReleaseStatus::tryFrom($request->query->getString('status'));
        $archived = $request->query->getBoolean('archived');

        return $this->render('release/index.html.twig', [
            'releases' => $releases->search($project, $status, $archived),
            'projects' => $projects->findAllOrdered(),
            'current_project' => $project,
            'current_status' => $status,
            'archived' => $archived,
        ]);
    }

    /** Étape 1 : choix du projet. */
    #[Route('/new', name: 'release_new', methods: ['GET'])]
    public function new(ProjectRepository $projects): Response
    {
        return $this->render('release/new_project.html.twig', ['projects' => $projects->findActive()]);
    }

    /** Étape 2 : chargement des versions Jira du projet. */
    #[Route('/new/{id}', name: 'release_new_versions', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function versions(Project $project, JiraVersionService $versionService, ReleaseRepository $releases, Request $request): Response
    {
        $versions = [];
        $error = null;
        try {
            $versions = $versionService->listVersions($project, $request->query->getBoolean('archived'));
        } catch (ExternalApiException $e) {
            $error = $e->getMessage();
        }
        $existing = [];
        foreach ($releases->findBy(['project' => $project]) as $release) {
            $existing[$release->getJiraVersionId()] = $release;
        }

        return $this->render('release/new_versions.html.twig', [
            'project' => $project,
            'versions' => $versions,
            'existing' => $existing,
            'error' => $error,
            'show_archived' => $request->query->getBoolean('archived'),
        ]);
    }

    /** Étape 3 : informations de la version et aperçu du regroupement, puis import. */
    #[Route('/new/{id}/{versionId}', name: 'release_new_import', requirements: ['id' => '\d+', 'versionId' => '\d+'], methods: ['GET', 'POST'])]
    public function import(Request $request, Project $project, string $versionId, ReleaseImportService $importService, ReleaseRepository $releases): Response
    {
        if (null !== $existing = $releases->findOneByProjectAndVersion($project, $versionId)) {
            $this->addFlash('info', 'Une release note existe déjà pour cette version : elle a été rouverte.');

            return $this->redirectToRoute('release_show', ['id' => $existing->getId()]);
        }

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'release-import-' . $project->getId() . '-' . $versionId);
            try {
                $release = $importService->import($project, $versionId, $request->getPayload()->getString('title'));
                $this->addFlash('success', sprintf('Release note créée en brouillon : %d ticket(s) importé(s) dans %d section(s).', $release->getTickets()->count(), $release->getSections()->count()));

                return $this->redirectToRoute('release_show', ['id' => $release->getId()]);
            } catch (ReleaseAlreadyExistsException $e) {
                $this->addFlash('info', $e->getMessage());

                return $this->redirectToRoute('release_show', ['id' => $e->existing->getId()]);
            } catch (ExternalApiException $e) {
                $this->addFlash('danger', 'Import impossible : ' . $e->getMessage());

                return $this->redirectToRoute('release_new_import', ['id' => $project->getId(), 'versionId' => $versionId]);
            }
        }

        $preview = null;
        $error = null;
        try {
            $preview = $importService->preview($project, $versionId);
        } catch (ExternalApiException $e) {
            $error = $e->getMessage();
        }

        return $this->render('release/new_import.html.twig', [
            'project' => $project,
            'version_id' => $versionId,
            'preview' => $preview,
            'error' => $error,
        ]);
    }

    /** Éditeur de la release note. */
    #[Route('/{id}', name: 'release_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Release $release): Response
    {
        $sections = $release->getSections()->toArray();
        usort($sections, static fn ($a, $b) => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        return $this->render('release/show.html.twig', [
            'release' => $release,
            'sections' => $sections,
            'formats' => PublicationFormat::cases(),
        ]);
    }

    #[Route('/{id}/edit', name: 'release_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Release $release): Response
    {
        if ($release->isArchived()) {
            $this->addFlash('warning', 'Release archivée : désarchivez-la pour la modifier.');

            return $this->redirectToRoute('release_show', ['id' => $release->getId()]);
        }
        $allowStatus = \in_array($release->getStatus(), ReleaseStatus::editorialChoices(), true);
        $form = $this->createForm(ReleaseMetadataType::class, $release, ['allow_status' => $allowStatus]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Informations de la release enregistrées.');

            return $this->redirectToRoute('release_show', ['id' => $release->getId()]);
        }

        return $this->render('release/edit.html.twig', ['release' => $release, 'form' => $form]);
    }

    #[Route('/{id}/status', name: 'release_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function status(Request $request, Release $release): Response
    {
        $this->assertCsrf($request, 'release-status-' . $release->getId());
        $status = ReleaseStatus::tryFrom($request->getPayload()->getString('status'));
        if (null === $status || !\in_array($status, ReleaseStatus::editorialChoices(), true) || $release->isArchived()) {
            $this->addFlash('danger', 'Changement de statut non autorisé.');
        } else {
            $release->setStatus($status);
            $this->em->flush();
            $this->addFlash('success', sprintf('Statut : %s.', $status->label()));
        }

        return $this->redirectToRoute('release_show', ['id' => $release->getId()]);
    }

    /** Aperçu de l'actualisation (rien n'est modifié). */
    #[Route('/{id}/refresh', name: 'release_refresh', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function refreshPreview(Release $release, ReleaseRefreshService $refreshService): Response
    {
        if ($release->isArchived()) {
            $this->addFlash('warning', 'Release archivée : actualisation impossible.');

            return $this->redirectToRoute('release_show', ['id' => $release->getId()]);
        }
        $plan = null;
        $error = null;
        try {
            $plan = $refreshService->plan($release);
        } catch (ExternalApiException $e) {
            $error = $e->getMessage();
            $release->recordSyncError($e->getMessage());
            $this->em->flush();
        }

        return $this->render('release/refresh.html.twig', ['release' => $release, 'plan' => $plan, 'error' => $error]);
    }

    #[Route('/{id}/refresh', name: 'release_refresh_apply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refreshApply(Request $request, Release $release, ReleaseRefreshService $refreshService): Response
    {
        $this->assertCsrf($request, 'release-refresh-' . $release->getId());
        if ($release->isArchived()) {
            return $this->redirectToRoute('release_show', ['id' => $release->getId()]);
        }
        try {
            $report = $refreshService->apply(
                $release,
                $request->getPayload()->getBoolean('create_sections'),
                $request->getPayload()->getBoolean('detach_removed'),
            );
            $this->addFlash('success', sprintf(
                'Actualisation terminée : %d ajouté(s), %d actualisé(s), %d inchangé(s), %d retiré(s) de la version, %d inaccessible(s), %d section(s) créée(s). Le wording n\'a pas été modifié.',
                $report['added'], $report['updated'], $report['unchanged'], $report['removed'], $report['inaccessible'], $report['sections_created']
            ));
        } catch (ExternalApiException $e) {
            $this->addFlash('danger', 'Actualisation impossible : ' . $e->getMessage());
        }

        return $this->redirectToRoute('release_show', ['id' => $release->getId()]);
    }

    #[Route('/{id}/archive', name: 'release_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(Request $request, Release $release): Response
    {
        $this->assertCsrf($request, 'release-archive-' . $release->getId());
        $release->archive();
        $this->em->flush();
        $this->addFlash('success', 'Release archivée (lecture seule). Les pages Confluence publiées ne sont pas modifiées.');

        return $this->redirectToRoute('release_show', ['id' => $release->getId()]);
    }

    #[Route('/{id}/unarchive', name: 'release_unarchive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unarchive(Request $request, Release $release): Response
    {
        $this->assertCsrf($request, 'release-unarchive-' . $release->getId());
        $release->unarchive();
        $this->em->flush();
        $this->addFlash('success', 'Release désarchivée.');

        return $this->redirectToRoute('release_show', ['id' => $release->getId()]);
    }

    #[Route('/{id}/delete', name: 'release_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function delete(Request $request, Release $release): Response
    {
        $published = $release->hasSuccessfulPublication();
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'release-delete-' . $release->getId());
            if ($published && $request->getPayload()->getString('confirmation') !== $release->getJiraVersionName()) {
                $this->addFlash('danger', 'Confirmation incorrecte : saisissez exactement le nom de la version pour supprimer une release publiée.');

                return $this->redirectToRoute('release_delete', ['id' => $release->getId()]);
            }
            $name = $release->getDisplayName();
            $projectId = $release->getProject()?->getId();
            $this->em->remove($release);
            $this->em->flush();
            $this->addFlash('success', sprintf('Release « %s » supprimée%s.', $name, $published ? ' (les pages Confluence existantes n\'ont pas été supprimées)' : ''));

            return $this->redirectToRoute('release_index', ['project' => $projectId]);
        }

        return $this->render('release/delete.html.twig', ['release' => $release, 'published' => $published]);
    }

    #[Route('/{id}/duplicate', name: 'release_duplicate', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function duplicate(Request $request, Release $release, JiraVersionService $versionService, ReleaseRepository $releases, ReleaseDuplicationService $duplication): Response
    {
        $project = $release->getProject();
        \assert(null !== $project);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'release-duplicate-' . $release->getId());
            $versionId = $request->getPayload()->getString('version_id');
            if (!ctype_digit($versionId)) {
                $this->addFlash('danger', 'Version invalide.');

                return $this->redirectToRoute('release_duplicate', ['id' => $release->getId()]);
            }
            try {
                $copy = $duplication->duplicate($release, $versionId, $request->getPayload()->getString('title'));
                $this->addFlash('success', 'Structure éditoriale dupliquée sur la nouvelle version : vérifiez les sections et le wording.');

                return $this->redirectToRoute('release_show', ['id' => $copy->getId()]);
            } catch (ReleaseAlreadyExistsException $e) {
                $this->addFlash('warning', $e->getMessage());

                return $this->redirectToRoute('release_show', ['id' => $e->existing->getId()]);
            } catch (ExternalApiException $e) {
                $this->addFlash('danger', 'Duplication impossible : ' . $e->getMessage());

                return $this->redirectToRoute('release_duplicate', ['id' => $release->getId()]);
            }
        }

        $versions = [];
        $error = null;
        try {
            $used = array_map(static fn (Release $r) => $r->getJiraVersionId(), $releases->findBy(['project' => $project]));
            $versions = array_values(array_filter($versionService->listVersions($project), static fn ($v) => !\in_array($v->id, $used, true)));
        } catch (ExternalApiException $e) {
            $error = $e->getMessage();
        }

        return $this->render('release/duplicate.html.twig', ['release' => $release, 'versions' => $versions, 'error' => $error]);
    }

    /** Aperçu d'un format (rendu web fidèle + avertissements éditoriaux). */
    #[Route('/{id}/preview/{format}', name: 'release_preview', requirements: ['id' => '\d+', 'format' => 'it|functional|communication'], methods: ['GET'])]
    public function preview(Release $release, string $format, ReleaseNoteRenderer $renderer): Response
    {
        $publicationFormat = PublicationFormat::from($format);

        return $this->render('release/preview.html.twig', [
            'release' => $release,
            'format' => $publicationFormat,
            'formats' => PublicationFormat::cases(),
            'view' => $renderer->buildView($release, $publicationFormat),
            'html' => $renderer->renderWeb($release, $publicationFormat),
        ]);
    }

    /** Source au format de stockage Confluence (ce qui sera réellement envoyé). */
    #[Route('/{id}/preview/{format}/storage', name: 'release_preview_storage', requirements: ['id' => '\d+', 'format' => 'it|functional|communication'], methods: ['GET'])]
    public function previewStorage(Release $release, string $format, ReleaseNoteRenderer $renderer): Response
    {
        $publicationFormat = PublicationFormat::from($format);
        $error = null;
        $storage = '';
        try {
            $storage = $renderer->renderStorage($release, $publicationFormat);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        return $this->render('release/preview_storage.html.twig', [
            'release' => $release,
            'format' => $publicationFormat,
            'formats' => PublicationFormat::cases(),
            'storage' => $storage,
            'page_title' => $renderer->pageTitle($release, $publicationFormat),
            'error' => $error,
        ]);
    }
}
