<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ExternalConnection;
use App\Entity\Project;
use App\Enum\PublicationTarget;
use App\Service\Confluence\ConfluenceConnectionTester;
use App\Service\Http\ExternalApiException;
use App\Service\Jira\JiraConnectionTester;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Validation de bout en bout de la configuration d'un projet :
 * connexion Jira + projet Jira, puis pour chaque Confluence configuré :
 * connexion, espace et page parente éventuelle.
 */
class ProjectConfigurationChecker
{
    public function __construct(
        private readonly JiraConnectionTester $jiraTester,
        private readonly ConfluenceConnectionTester $confluenceTester,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array<string, array{ok: bool, message: string}> */
    public function check(Project $project): array
    {
        $results = [];

        $jira = $project->getJiraConnection();
        if (null === $jira) {
            $results['Jira'] = ['ok' => false, 'message' => 'Aucune connexion Jira.'];
        } else {
            $results['Jira — connexion'] = $this->run($jira, fn () => $this->jiraTester->test($jira));
            if ($results['Jira — connexion']['ok']) {
                $results['Jira — projet ' . $project->getJiraProjectKey()] = $this->run($jira, fn () => $this->jiraTester->checkProject($jira, $project->getJiraProjectKey()));
            }
        }

        foreach (PublicationTarget::cases() as $target) {
            $connection = $project->getConfluenceConnection($target);
            $space = $project->getConfluenceSpaceKey($target);
            $label = $target->label();
            if (null === $connection || null === $space) {
                $results[$label] = ['ok' => true, 'message' => 'Non configuré (cible ignorée à la publication).'];
                continue;
            }
            $results[$label . ' — connexion'] = $this->run($connection, fn () => $this->confluenceTester->test($connection));
            if (!$results[$label . ' — connexion']['ok']) {
                continue;
            }
            $results[$label . ' — espace ' . $space] = $this->run($connection, fn () => $this->confluenceTester->checkSpace($connection, $space));
            $parent = $project->getConfluenceParentPageId($target);
            if (null !== $parent) {
                $results[$label . ' — page parente'] = $this->run($connection, fn () => $this->confluenceTester->checkParentPage($connection, $parent));
            }
        }

        $project->recordCheckResult($results);
        $this->em->flush();

        return $results;
    }

    /**
     * @param callable(): string $check
     *
     * @return array{ok: bool, message: string}
     */
    private function run(ExternalConnection $connection, callable $check): array
    {
        if (!$connection->isActive()) {
            return ['ok' => false, 'message' => sprintf('Connexion « %s » désactivée.', $connection->getName())];
        }
        try {
            return ['ok' => true, 'message' => $check()];
        } catch (ExternalApiException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
