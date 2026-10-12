<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\IssueTypeMapping;
use App\Form\IssueTypeMappingType;
use App\Repository\IssueTypeMappingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/settings/issue-types')]
final class IssueTypeMappingController extends AbstractAppController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'mapping_index', methods: ['GET'])]
    public function index(IssueTypeMappingRepository $repository): Response
    {
        return $this->render('issue_type_mapping/index.html.twig', ['mappings' => $repository->findAllOrdered()]);
    }

    #[Route('/new', name: 'mapping_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handle($request, new IssueTypeMapping(), true);
    }

    #[Route('/{id}/edit', name: 'mapping_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, IssueTypeMapping $mapping): Response
    {
        return $this->handle($request, $mapping, false);
    }

    #[Route('/{id}/delete', name: 'mapping_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, IssueTypeMapping $mapping): Response
    {
        $this->assertCsrf($request, 'mapping-delete-' . $mapping->getId());
        $this->em->remove($mapping);
        $this->em->flush();
        $this->addFlash('success', 'Correspondance supprimée. Les releases existantes ne sont pas modifiées.');

        return $this->redirectToRoute('mapping_index');
    }

    private function handle(Request $request, IssueTypeMapping $mapping, bool $isNew): Response
    {
        $form = $this->createForm(IssueTypeMappingType::class, $mapping);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->em->persist($mapping);
            }
            $this->em->flush();
            $this->addFlash('success', 'Correspondance enregistrée. Elle s\'appliquera aux prochains imports et actualisations.');

            return $this->redirectToRoute('mapping_index');
        }

        return $this->render('issue_type_mapping/form.html.twig', ['form' => $form, 'mapping' => $mapping, 'is_new' => $isNew]);
    }
}
