<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BackupSettingsRepository;
use App\Service\Backup\BackupException;
use App\Service\Backup\BackupManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration › Sauvegarde : base de données et fichiers choisis, dans une archive zip
 * déposée dans un répertoire. Accessible depuis le poste seulement (LocalOnlyListener).
 */
#[Route('/backups', name: 'backup')]
final class BackupController extends AbstractAppController
{
    public function __construct(
        private readonly BackupSettingsRepository $settings,
        private readonly BackupManager $backups,
    ) {
    }

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(): Response
    {
        $settings = $this->settings->getSettings();
        $check = null;
        if (null !== $settings->getDestination()) {
            try {
                $this->backups->checkDestination($settings->getDestination(), false);
                $check = ['ok' => true, 'message' => is_dir((string) $settings->getDestination()) ? 'Répertoire accessible en écriture.' : 'Le répertoire n\'existe pas encore : il sera créé à la première sauvegarde.'];
            } catch (BackupException $e) {
                $check = ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        return $this->render('backup/index.html.twig', [
            'settings' => $settings,
            'check' => $check,
            'backups' => $this->backups->list($settings->getDestination()),
            'projectDir' => $this->backups->projectDir(),
            'zip' => class_exists(\ZipArchive::class),
        ]);
    }

    #[Route('/settings', name: '_settings', methods: ['POST'])]
    public function saveSettings(Request $request, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'backup-settings');
        $settings = $this->settings->getSettings()
            ->setDestination((string) $request->request->get('destination'))
            ->setPaths((string) $request->request->get('paths'))
            ->setIncludeDatabase($request->request->getBoolean('includeDatabase'))
            ->setKeep(max(0, (int) $request->request->get('keep', 10)));
        try {
            if (null !== $settings->getDestination()) {
                $this->backups->checkDestination($settings->getDestination(), false);
            }
            $this->addFlash('success', 'Paramètres de sauvegarde enregistrés.');
        } catch (BackupException $e) {
            $this->addFlash('warning', 'Paramètres enregistrés, mais : '.$e->getMessage());
        }
        $em->flush();

        return $this->redirectToRoute('backup_index');
    }

    #[Route('/run', name: '_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $this->assertCsrf($request, 'backup-run');
        @set_time_limit(0);
        try {
            $result = $this->backups->run($this->settings->getSettings());
            $this->addFlash('success', \sprintf('Sauvegarde créée : %s (%s, base : %s, %d fichier(s), %d ms).',
                $result->file, $this->size($result->size), $result->databaseFormat, $result->fileCount(), $result->durationMs));
            foreach ($result->warnings as $warning) {
                $this->addFlash('warning', $warning);
            }
            if ([] !== $result->deleted) {
                $this->addFlash('info', \count($result->deleted).' ancienne(s) sauvegarde(s) supprimée(s) : '.implode(', ', $result->deleted).'.');
            }
        } catch (BackupException $e) {
            $this->addFlash('danger', 'Sauvegarde impossible : '.$e->getMessage());
        }

        return $this->redirectToRoute('backup_index');
    }

    #[Route('/download/{name}', name: '_download', methods: ['GET'], requirements: ['name' => BackupManager::PREFIX.'[0-9_]+\.zip'])]
    public function download(string $name): Response
    {
        $path = $this->backups->find($this->settings->getSettings()->getDestination(), $name) ?? throw $this->createNotFoundException('Sauvegarde introuvable.');

        return (new BinaryFileResponse($path))->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);
    }

    private function size(int $bytes): string
    {
        return str_replace('.', ',', $bytes < 1048576 ? round($bytes / 1024, 1).' Ko' : round($bytes / 1048576, 1).' Mo');
    }
}
