<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ReleaseStatus;
use App\Repository\ExternalConnectionRepository;
use App\Repository\ProjectRepository;
use App\Repository\PublicationRepository;
use App\Repository\ReleaseRepository;
use App\Service\Security\SecretEncryptionService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractAppController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(
        ProjectRepository $projects,
        ReleaseRepository $releases,
        PublicationRepository $publications,
        ExternalConnectionRepository $connections,
        SecretEncryptionService $encryption,
    ): Response {
        $failingProjects = array_values(array_filter($projects->findAllOrdered(), static fn ($p) => $p->hasConfigurationErrors()));

        return $this->render('dashboard/index.html.twig', [
            'projects' => $projects->findAllOrdered(),
            'latest' => $releases->findLatest(8),
            'drafts' => $releases->findByStatuses([ReleaseStatus::Draft, ReleaseStatus::InPreparation], 10),
            'ready' => $releases->findByStatuses([ReleaseStatus::Ready, ReleaseStatus::PartiallyPublished], 10),
            'counts' => $releases->countByStatus(),
            'recent_publications' => $publications->findRecent(8),
            'failed_publications' => $publications->findFailed(8),
            'sync_errors' => $releases->findWithSyncErrors(8),
            'failing_connections' => $connections->findFailing(),
            'failing_projects' => $failingProjects,
            'encryption_ready' => $encryption->isConfigured(),
            'connection_count' => \count($connections->findAll()),
        ]);
    }
}
