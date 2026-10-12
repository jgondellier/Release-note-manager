<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ExternalConnection;
use App\Enum\ConnectionTestStatus;
use App\Service\Confluence\ConfluenceConnectionTester;
use App\Service\Http\ExternalApiException;
use App\Service\Jira\JiraConnectionTester;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lance le test adapté (Jira / Confluence) et enregistre le résultat sur la connexion.
 * Le test est exécuté même si la connexion est inactive (pour diagnostic).
 */
class ConnectionTestService
{
    public function __construct(
        private readonly JiraConnectionTester $jiraTester,
        private readonly ConfluenceConnectionTester $confluenceTester,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $integrationLogger,
    ) {
    }

    /** @return array{ok: bool, message: string} */
    public function test(ExternalConnection $connection): array
    {
        $wasActive = $connection->isActive();
        $connection->setActive(true);
        try {
            $message = $connection->isJira()
                ? $this->jiraTester->test($connection)
                : $this->confluenceTester->test($connection);
            $connection->recordTestResult(ConnectionTestStatus::Success, $message);
            $result = ['ok' => true, 'message' => $message];
        } catch (ExternalApiException $e) {
            $connection->recordTestResult(ConnectionTestStatus::Failure, $e->getMessage());
            $result = ['ok' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            $this->integrationLogger->error('Erreur inattendue lors du test de connexion', ['connection_id' => $connection->getId(), 'error' => $e::class]);
            $message = 'Erreur inattendue pendant le test (voir les journaux applicatifs).';
            $connection->recordTestResult(ConnectionTestStatus::Failure, $message);
            $result = ['ok' => false, 'message' => $message];
        } finally {
            $connection->setActive($wasActive);
        }
        $this->em->flush();

        return $result;
    }
}
