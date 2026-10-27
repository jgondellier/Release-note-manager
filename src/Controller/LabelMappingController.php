<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LabelMapping;
use App\Form\LabelMappingType;
use App\Repository\LabelMappingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/settings/labels')]
final class LabelMappingController extends AbstractAppController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'label_mapping_index', methods: ['GET'])]
    public function index(LabelMappingRepository $repository): Response
    {
        return $this->render('label_mapping/index.html.twig', ['mappings' => $repository->findAllOrdered()]);
    }

    #[Route('/new', name: 'label_mapping_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $mapping = new LabelMapping();
        if ($label = $request->query->getString('label')) {
            $mapping->setLabel($label);
        }

        return $this->handle($request, $mapping, true);
    }

    #[Route('/{id}/edit', name: 'label_mapping_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, LabelMapping $mapping): Response
    {
        return $this->handle($request, $mapping, false);
    }

    #[Route('/{id}/delete', name: 'label_mapping_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, LabelMapping $mapping): Response
    {
        $this->assertCsrf($request, 'label-mapping-delete-' . $mapping->getId());
        $this->em->remove($mapping);
        $this->em->flush();
        $this->addFlash('success', 'Conversion supprimée. Les releases existantes ne sont pas modifiées.');

        return $this->redirectToRoute('label_mapping_index');
    }

    private function handle(Request $request, LabelMapping $mapping, bool $isNew): Response
    {
        $form = $this->createForm(LabelMappingType::class, $mapping);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->em->persist($mapping);
            }
            $this->em->flush();
            $this->addFlash('success', 'Conversion enregistrée. Elle s\'appliquera aux prochains imports et actualisations.');

            return $this->redirectToRoute('label_mapping_index');
        }

        return $this->render('label_mapping/form.html.twig', ['form' => $form, 'mapping' => $mapping, 'is_new' => $isNew]);
    }
}
