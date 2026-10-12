<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Form\ProjectType;
use App\Repository\ProjectRepository;
use App\Repository\ReleaseRepository;
use App\Service\ProjectConfigurationChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/projects')]
final class ProjectController extends AbstractAppController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'project_index', methods: ['GET'])]
    public function index(ProjectRepository $repository): Response
    {
        return $this->render('project/index.html.twig', ['projects' => $repository->findAllOrdered()]);
    }

    #[Route('/new', name: 'project_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $project = new Project();
        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($project);
            $this->em->flush();
            $this->addFlash('success', sprintf('Projet « %s » créé. Lancez la validation de configuration pour vérifier les accès.', $project->getName()));

            return $this->redirectToRoute('project_show', ['id' => $project->getId()]);
        }

        return $this->render('project/form.html.twig', ['form' => $form, 'project' => $project, 'is_new' => true]);
    }

    #[Route('/{id}', name: 'project_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Project $project, ReleaseRepository $releases): Response
    {
        return $this->render('project/show.html.twig', [
            'project' => $project,
            'releases' => $releases->search($project, null, true),
        ]);
    }

    #[Route('/{id}/edit', name: 'project_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Project $project): Response
    {
        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Projet mis à jour.');

            return $this->redirectToRoute('project_show', ['id' => $project->getId()]);
        }

        return $this->render('project/form.html.twig', ['form' => $form, 'project' => $project, 'is_new' => false]);
    }

    #[Route('/{id}/check', name: 'project_check', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function check(Request $request, Project $project, ProjectConfigurationChecker $checker): Response
    {
        $this->assertCsrf($request, 'project-check-' . $project->getId());
        $results = $checker->check($project);
        $failed = \count(array_filter($results, static fn (array $r) => !$r['ok']));
        $this->addFlash(0 === $failed ? 'success' : 'warning', 0 === $failed
            ? 'Configuration validée : tous les accès sont opérationnels.'
            : sprintf('%d contrôle(s) en échec : voir le détail ci-dessous.', $failed));

        return $this->redirectToRoute('project_show', ['id' => $project->getId()]);
    }

    #[Route('/{id}/toggle', name: 'project_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(Request $request, Project $project): Response
    {
        $this->assertCsrf($request, 'project-toggle-' . $project->getId());
        $project->setActive(!$project->isActive());
        $this->em->flush();
        $this->addFlash('success', $project->isActive() ? 'Projet réactivé.' : 'Projet désactivé : plus aucune release ne peut y être créée.');

        return $this->redirectToRoute('project_show', ['id' => $project->getId()]);
    }
}
