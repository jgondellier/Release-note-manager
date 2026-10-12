<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ExternalConnection;
use App\Entity\IssueTypeMapping;
use App\Entity\JiraTicket;
use App\Entity\Project;
use App\Entity\Release;
use App\Entity\ReleaseSection;
use App\Enum\AuthenticationType;
use App\Enum\ConnectionProvider;
use App\Enum\DeploymentType;
use App\Enum\ReleaseStatus;
use App\Enum\SectionCategory;
use App\Enum\SectionSourceType;
use App\Service\Security\SecretEncryptionService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Données de DÉMONSTRATION (environnements dev/test uniquement).
 *
 * Les connexions pointent vers des domaines « .invalid » et sont INACTIVES :
 * aucun appel réel à Jira/Confluence n'est possible avec ces données.
 * Chargement : php bin/console doctrine:fixtures:load  (purge la base !)
 */
final class DemoFixtures extends Fixture
{
    /** Correspondances par défaut (identiques à la migration initiale, recréées après purge). */
    private const DEFAULT_MAPPINGS = [
        ['Bug', 'Corrections', SectionCategory::Fix, 20],
        ['Bogue', 'Corrections', SectionCategory::Fix, 20],
        ['Anomalie', 'Corrections', SectionCategory::Fix, 20],
        ['Story', 'Évolutions', SectionCategory::Feature, 10],
        ['Récit', 'Évolutions', SectionCategory::Feature, 10],
        ['Improvement', 'Évolutions', SectionCategory::Feature, 10],
        ['Amélioration', 'Évolutions', SectionCategory::Feature, 10],
        ['New Feature', 'Évolutions', SectionCategory::Feature, 10],
        ['Nouvelle fonctionnalité', 'Évolutions', SectionCategory::Feature, 10],
        ['Task', 'Tâches techniques', SectionCategory::Technical, 30],
        ['Tâche', 'Tâches techniques', SectionCategory::Technical, 30],
        ['Sub-task', 'Tâches techniques', SectionCategory::Technical, 30],
        ['Sous-tâche', 'Tâches techniques', SectionCategory::Technical, 30],
        ['*', 'Autres changements', SectionCategory::Other, 90],
    ];

    public function __construct(private readonly SecretEncryptionService $encryption)
    {
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::DEFAULT_MAPPINGS as [$type, $title, $category, $position]) {
            $manager->persist((new IssueTypeMapping())->setJiraIssueType($type)->setSectionTitle($title)->setCategory($category)->setPosition($position));
        }

        $secret = $this->encryption->isConfigured() ? $this->encryption->encrypt('demo-token-non-fonctionnel') : null;
        $connection = function (string $name, ConnectionProvider $provider, DeploymentType $type, string $url, AuthenticationType $auth, ?string $user) use ($manager, $secret): ExternalConnection {
            $c = (new ExternalConnection())
                ->setName($name)->setProvider($provider)->setDeploymentType($type)->setBaseUrl($url)
                ->setAuthenticationType($auth)->setUsername($user)->setEncryptedSecret($secret)->setActive(false);
            $manager->persist($c);

            return $c;
        };

        $jiraCloud = $connection('[DÉMO] Jira Cloud', ConnectionProvider::Jira, DeploymentType::Cloud, 'https://demo-ccs.atlassian.invalid', AuthenticationType::Basic, 'demo@example.invalid');
        $jiraServer = $connection('[DÉMO] Jira Server Finagora', ConnectionProvider::Jira, DeploymentType::Server, 'https://jira.finagora.invalid', AuthenticationType::Bearer, null);
        $jiraServer->setJiraEpicLinkField('customfield_10100');
        $confCloud = $connection('[DÉMO] Confluence Cloud', ConnectionProvider::Confluence, DeploymentType::Cloud, 'https://demo-ccs.atlassian.invalid', AuthenticationType::Basic, 'demo@example.invalid');
        $confServer = $connection('[DÉMO] Confluence Data Center', ConnectionProvider::Confluence, DeploymentType::Server, 'https://confluence.interne.invalid/confluence', AuthenticationType::Bearer, null);

        $ccs = (new Project())
            ->setName('CCS')->setCode('CCS')->setDescription('Projet de démonstration (Jira Cloud).')
            ->setJiraConnection($jiraCloud)->setJiraProjectKey('CCS')
            ->setConfluenceCloudConnection($confCloud)->setConfluenceCloudSpaceKey('CCS')
            ->setConfluenceServerConnection($confServer)->setConfluenceServerSpaceKey('RELEASES')->setConfluenceServerParentPageId('123456');
        $finagora = (new Project())
            ->setName('Finagora')->setCode('FINAGORA')->setDescription('Projet de démonstration (Jira Server/Data Center).')
            ->setJiraConnection($jiraServer)->setJiraProjectKey('FIN')
            ->setConfluenceServerConnection($confServer)->setConfluenceServerSpaceKey('FIN');
        $manager->persist($ccs);
        $manager->persist($finagora);

        $release = (new Release())
            ->setProject($ccs)->setJiraVersionId('10042')->setJiraVersionName('2.4.0')
            ->setJiraVersionReleased(false)->setJiraVersionReleaseDate(new \DateTimeImmutable('+7 days'))
            ->setReleaseDate(new \DateTimeImmutable('+7 days'))
            ->setTitle('[DÉMO] CCS — version 2.4.0')->setStatus(ReleaseStatus::InPreparation)
            ->setItIntro("Version de maintenance évolutive.\n\nDéploiement prévu en heures ouvrées, sans interruption de service.")
            ->setFunctionalIntro('Cette version simplifie la gestion des dossiers et corrige plusieurs anomalies signalées par le support.')
            ->setCommunicationTitle('Nouveautés CCS 2.4')
            ->setCommunicationIntro('Gagnez du temps au quotidien grâce à la recherche avancée des dossiers.')
            ->setUsefulLinks('Guide utilisateur | https://example.invalid/guide');

        $epic = (new ReleaseSection())
            ->setSourceType(SectionSourceType::Epic)->setSourceKey('CCS-100')->setCategory(SectionCategory::Feature)
            ->setTitle('Recherche avancée des dossiers')->setPosition(10)
            ->setDescription('Nouvel écran de recherche multicritère.')
            ->setItContent("- Nouvel index de recherche sur la table `dossier`\n- API `GET /api/dossiers/search` paginée")
            ->setFunctionalContent("Les gestionnaires peuvent rechercher un dossier par **client**, **statut** et **période**.\n\n- Filtres combinables\n- Export CSV des résultats")
            ->setCommunicationContent('Retrouvez n\'importe quel dossier en quelques secondes grâce aux nouveaux filtres.')
            ->setImpact('Écran « Dossiers » réorganisé.')
            ->setAudience('Gestionnaires, superviseurs');
        $fixes = (new ReleaseSection())
            ->setSourceType(SectionSourceType::IssueType)->setSourceKey('Corrections')->setCategory(SectionCategory::Fix)
            ->setTitle('Corrections')->setPosition(20)
            ->setFunctionalContent('Corrections d\'anomalies sur l\'édition des courriers.');
        $tech = (new ReleaseSection())
            ->setSourceType(SectionSourceType::IssueType)->setSourceKey('Tâches techniques')->setCategory(SectionCategory::Technical)
            ->setTitle('Tâches techniques')->setPosition(30)->applyDefaultVisibility()
            ->setItContent('Montée de version des dépendances.')
            ->setActionRequired('Vider le cache applicatif après déploiement.');
        foreach ([$epic, $fixes, $tech] as $section) {
            $release->addSection($section);
        }

        $tickets = [
            [$epic, '20100', 'CCS-100', 'Epic', 'Recherche avancée des dossiers', 'Terminé', 'done'],
            [$epic, '20101', 'CCS-101', 'Story', 'Filtrer les dossiers par statut et période', 'Terminé', 'done'],
            [$epic, '20102', 'CCS-102', 'Story', 'Exporter les résultats de recherche en CSV', 'En cours', 'indeterminate'],
            [$fixes, '20110', 'CCS-110', 'Bug', 'Le courrier de relance affiche une date erronée', 'Terminé', 'done'],
            [$tech, '20120', 'CCS-120', 'Task', 'Mise à jour des dépendances', 'Terminé', 'done'],
        ];
        foreach ($tickets as $i => [$section, $id, $key, $type, $summary, $status, $category]) {
            $ticket = (new JiraTicket())
                ->setJiraIssueId($id)->setJiraKey($key)->setIssueType($type)->setSummary($summary)
                ->setStatus($status)->setStatusCategory($category)->setPriority('Medium')
                ->setEpicKey($section === $epic ? 'CCS-100' : null)->setEpicName($section === $epic ? 'Recherche avancée des dossiers' : null)
                ->setComponents(['Back-office'])->setLabels([])->setFixVersions([['id' => '10042', 'name' => '2.4.0']])
                ->setJiraUrl('https://demo-ccs.atlassian.invalid/browse/' . $key)
                ->setPosition(($i + 1) * 10)->setLastSyncedAt(new \DateTimeImmutable());
            $release->addTicket($ticket);
            $section->addTicket($ticket);
        }
        $release->recordSync(['type' => 'import', 'added' => \count($tickets), 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'inaccessible' => 0, 'sections_created' => 3]);

        $manager->persist($release);
        $manager->flush();
    }
}
