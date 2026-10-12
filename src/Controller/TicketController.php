<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\JiraTicket;
use App\Entity\Release;
use App\Repository\ReleaseSectionRepository;
use App\Service\Http\ExternalApiException;
use App\Service\ReleaseNote\ReleaseSectionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TicketController extends AbstractAppController
{
    public function __construct(
        private readonly ReleaseSectionService $sectionService,
        private readonly ReleaseSectionRepository $sections,
    ) {
    }

    /** Consultation du ticket Jira source. */
    #[Route('/tickets/{id}', name: 'ticket_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(JiraTicket $ticket): Response
    {
        return $this->render('ticket/show.html.twig', ['ticket' => $ticket, 'release' => $ticket->getRelease()]);
    }

    /** Déplacement vers une autre section (section_id vide = « Non classés »). */
    #[Route('/tickets/{id}/move', name: 'ticket_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function move(Request $request, JiraTicket $ticket): Response
    {
        $this->assertCsrf($request, 'ticket-ops-' . $ticket->getRelease()?->getId());
        $sectionId = $request->getPayload()->getInt('section_id');
        $target = $sectionId > 0 ? $this->sections->find($sectionId) : null;
        try {
            if ($sectionId > 0 && null === $target) {
                throw new \InvalidArgumentException('Section introuvable.');
            }
            $this->sectionService->moveTicket($ticket, $target);
            $this->addFlash('success', sprintf('%s déplacé vers « %s ».', $ticket->getJiraKey(), $target?->getTitle() ?? 'Non classés'));
        } catch (\InvalidArgumentException|\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->backToRelease($ticket);
    }

    #[Route('/tickets/{id}/position/{direction}', name: 'ticket_position', requirements: ['id' => '\d+', 'direction' => 'up|down'], methods: ['POST'])]
    public function position(Request $request, JiraTicket $ticket, string $direction): Response
    {
        $this->assertCsrf($request, 'ticket-ops-' . $ticket->getRelease()?->getId());
        try {
            $this->sectionService->moveTicketWithinSection($ticket, $direction);
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->backToRelease($ticket);
    }

    #[Route('/tickets/{id}/exclude', name: 'ticket_exclude', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function exclude(Request $request, JiraTicket $ticket): Response
    {
        $this->assertCsrf($request, 'ticket-ops-' . $ticket->getRelease()?->getId());
        try {
            $this->sectionService->excludeTicket($ticket);
            $this->addFlash('success', sprintf('%s retiré de la release (il ne sera pas ré-ajouté par l\'actualisation).', $ticket->getJiraKey()));
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->backToRelease($ticket);
    }

    #[Route('/tickets/{id}/include', name: 'ticket_include', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function include(Request $request, JiraTicket $ticket): Response
    {
        $this->assertCsrf($request, 'ticket-ops-' . $ticket->getRelease()?->getId());
        try {
            $this->sectionService->includeTicket($ticket);
            $this->addFlash('success', sprintf('%s de nouveau inclus (dans « Non classés »).', $ticket->getJiraKey()));
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->backToRelease($ticket);
    }

    #[Route('/releases/{id}/tickets/add', name: 'ticket_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function add(Request $request, Release $release): Response
    {
        $this->assertCsrf($request, 'ticket-add-' . $release->getId());
        $sectionId = $request->getPayload()->getInt('section_id');
        $target = $sectionId > 0 ? $this->sections->find($sectionId) : null;
        if (null !== $target && $target->getRelease() !== $release) {
            $target = null;
        }
        try {
            $result = $this->sectionService->addTicketByKey($release, $request->getPayload()->getString('key'), $target);
            $this->addFlash('success', sprintf('%s ajouté%s.', $result['ticket']->getJiraKey(), null !== $result['ticket']->getSection() ? ' dans « ' . $result['ticket']->getSection()->getTitle() . ' »' : ' (non classé)'));
            if (null !== $result['warning']) {
                $this->addFlash('warning', $result['warning']);
            }
        } catch (\InvalidArgumentException|\LogicException|ExternalApiException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('release_show', ['id' => $release->getId(), '_fragment' => 'tickets']);
    }

    private function backToRelease(JiraTicket $ticket): Response
    {
        $section = $ticket->getSection();

        return $this->redirectToRoute('release_show', [
            'id' => $ticket->getRelease()?->getId(),
            '_fragment' => null !== $section ? 'section-' . $section->getId() : 'unassigned',
        ]);
    }
}
