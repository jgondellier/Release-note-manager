<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ExternalConnection;
use App\Form\ConnectionType;
use App\Repository\ExternalConnectionRepository;
use App\Service\ConnectionTestService;
use App\Service\Http\HttpClientOptionsProvider;
use App\Service\Security\SecretEncryptionException;
use App\Service\Security\SecretEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/connections')]
final class ConnectionController extends AbstractAppController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SecretEncryptionService $encryption,
    ) {
    }

    #[Route('', name: 'connection_index', methods: ['GET'])]
    public function index(ExternalConnectionRepository $repository, HttpClientOptionsProvider $httpOptions): Response
    {
        return $this->render('connection/index.html.twig', [
            'connections' => $repository->findAllOrdered(),
            'http_config' => $httpOptions->describe(),
            'encryption_ready' => $this->encryption->isConfigured(),
        ]);
    }

    #[Route('/new', name: 'connection_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $connection = new ExternalConnection();
        $form = $this->createForm(ConnectionType::class, $connection, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->applySecret($form, $connection)) {
            $this->em->persist($connection);
            $this->em->flush();
            $this->addFlash('success', sprintf('Connexion « %s » créée. Pensez à la tester.', $connection->getName()));

            return $this->redirectToRoute('connection_show', ['id' => $connection->getId()]);
        }

        return $this->render('connection/form.html.twig', ['form' => $form, 'connection' => $connection, 'is_new' => true]);
    }

    #[Route('/{id}', name: 'connection_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ExternalConnection $connection, ExternalConnectionRepository $repository): Response
    {
        return $this->render('connection/show.html.twig', [
            'connection' => $connection,
            'usage' => $repository->countProjectsUsing($connection),
        ]);
    }

    #[Route('/{id}/edit', name: 'connection_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, ExternalConnection $connection): Response
    {
        $before = [$connection->getBaseUrl(), $connection->getAuthenticationType(), $connection->getUsername(), $connection->getDeploymentType()];
        $form = $this->createForm(ConnectionType::class, $connection);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->applySecret($form, $connection)) {
            $after = [$connection->getBaseUrl(), $connection->getAuthenticationType(), $connection->getUsername(), $connection->getDeploymentType()];
            if ($before !== $after || '' !== (string) $form->get('plainSecret')->getData()) {
                $connection->resetTestResult();
            }
            $this->em->flush();
            $this->addFlash('success', 'Connexion mise à jour.');

            return $this->redirectToRoute('connection_show', ['id' => $connection->getId()]);
        }

        return $this->render('connection/form.html.twig', ['form' => $form, 'connection' => $connection, 'is_new' => false]);
    }

    #[Route('/{id}/test', name: 'connection_test', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function test(Request $request, ExternalConnection $connection, ConnectionTestService $tester): Response
    {
        $this->assertCsrf($request, 'connection-test-' . $connection->getId());
        $result = $tester->test($connection);
        $this->addFlash($result['ok'] ? 'success' : 'danger', ($result['ok'] ? 'Test réussi : ' : 'Échec du test : ') . $result['message']);

        // Retour contrôlé (pas de redirection vers le Referer).
        return 'index' === $request->getPayload()->get('_return')
            ? $this->redirectToRoute('connection_index')
            : $this->redirectToRoute('connection_show', ['id' => $connection->getId()]);
    }

    #[Route('/{id}/toggle', name: 'connection_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(Request $request, ExternalConnection $connection): Response
    {
        $this->assertCsrf($request, 'connection-toggle-' . $connection->getId());
        $connection->setActive(!$connection->isActive());
        $this->em->flush();
        $this->addFlash('success', $connection->isActive() ? 'Connexion réactivée.' : 'Connexion désactivée : elle ne sera plus utilisée pour les imports ni les publications.');

        return $this->redirectToRoute('connection_show', ['id' => $connection->getId()]);
    }

    private function applySecret(FormInterface $form, ExternalConnection $connection): bool
    {
        $secret = trim((string) $form->get('plainSecret')->getData()); // espaces parasites d'un copier-coller
        if ('' === $secret) {
            if (!$connection->hasSecret()) {
                $form->get('plainSecret')->addError(new FormError('Le token est obligatoire.'));

                return false;
            }

            return true;
        }
        try {
            $connection->setEncryptedSecret($this->encryption->encrypt($secret));
        } catch (SecretEncryptionException $e) {
            $form->get('plainSecret')->addError(new FormError($e->getMessage()));

            return false;
        }

        return true;
    }
}
