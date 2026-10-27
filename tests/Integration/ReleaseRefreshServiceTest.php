<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\LabelMapping;
use App\Entity\Release;
use App\Enum\TicketSyncState;
use App\Service\ReleaseNote\ReleaseImportService;
use App\Service\ReleaseNote\ReleaseRefreshService;
use App\Service\ReleaseNote\ReleaseSectionService;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\JiraFixtures;

final class ReleaseRefreshServiceTest extends DatabaseTestCase
{
    public function testRefreshAddsNewTicketsWithoutTouchingWordingOrClassification(): void
    {
        $project = $this->createProject();
        $issues = [
            JiraFixtures::issue('CCS-1', 'Epic', 'Recherche', [], '10042', true),
            JiraFixtures::issue('CCS-2', 'Story', 'Filtrer', ['parent' => JiraFixtures::parent('CCS-1', 'Recherche')]),
            JiraFixtures::issue('CCS-4', 'Bug', 'Date erronée'),
            JiraFixtures::issue('CCS-5', 'Bug', 'Libellé faux'),
        ];
        $this->fakeJiraCloud($issues);
        $release = $this->service(ReleaseImportService::class)->import($project, '10042');

        // Travail éditorial de l'utilisateur.
        $epicSection = $release->getSections()->first();
        $epicSection->setTitle('Nouvelle recherche multicritère')
            ->setFunctionalContent('Texte fonctionnel rédigé à la main')
            ->setCommunicationContent('Texte de communication');
        $fixes = $release->getSections()->get(1);
        /** @var ReleaseSectionService $sections */
        $sections = $this->service(ReleaseSectionService::class);
        // Classement manuel : CCS-4 déplacé dans la section d'Epic.
        $sections->moveTicket($release->findTicketByKey('CCS-4'), $epicSection);
        $this->em->flush();

        // Évolution côté Jira : résumé et Epic modifiés, un nouveau ticket, un ticket retiré de la version, un supprimé.
        $issues[0]['fields']['summary'] = 'Titre Epic changé dans Jira';
        $issues[1]['fields']['summary'] = 'Filtrer (résumé modifié)';
        $issues[2]['fields']['fixVersions'] = [['id' => '10043', 'name' => '2.5.0']];
        unset($issues[3]);
        $issues = array_values($issues);
        $issues[] = JiraFixtures::issue('CCS-7', 'Story', 'Nouvelle story', ['parent' => JiraFixtures::parent('CCS-1', 'Titre Epic changé dans Jira')]);
        $issues[] = JiraFixtures::issue('CCS-8', 'Task', 'Nouvelle tâche');

        /** @var ReleaseRefreshService $refresh */
        $refresh = $this->service(ReleaseRefreshService::class);
        $plan = $refresh->plan($release);
        self::assertCount(2, $plan->new);
        self::assertCount(1, $plan->removedFromVersion);
        self::assertCount(1, $plan->inaccessible);
        self::assertTrue($plan->hasEditorialImpact());

        $report = $refresh->apply($release, true, false);
        self::assertSame(2, $report['added']);
        self::assertSame(1, $report['removed']);
        self::assertSame(1, $report['inaccessible']);
        self::assertSame(1, $report['sections_created']);

        $this->em->clear();
        $release = $this->em->find(Release::class, $release->getId());
        $epicSection = $release->getSections()->first();

        // Wording intact.
        self::assertSame('Nouvelle recherche multicritère', $epicSection->getTitle());
        self::assertSame('Texte fonctionnel rédigé à la main', $epicSection->getFunctionalContent());
        self::assertSame('Texte de communication', $epicSection->getCommunicationContent());
        // Classement manuel intact, ticket signalé mais non détaché.
        $ccs4 = $release->findTicketByKey('CCS-4');
        self::assertSame($epicSection->getId(), $ccs4->getSection()?->getId());
        self::assertSame(TicketSyncState::RemovedFromVersion, $ccs4->getSyncState());
        self::assertSame(TicketSyncState::Inaccessible, $release->findTicketByKey('CCS-5')->getSyncState());
        // Métadonnées actualisées.
        self::assertSame('Filtrer (résumé modifié)', $release->findTicketByKey('CCS-2')->getSummary());
        // Nouveau ticket rattaché à la section d'Epic existante, nouvelle section créée pour la tâche.
        self::assertSame($epicSection->getId(), $release->findTicketByKey('CCS-7')->getSection()?->getId());
        self::assertSame('Tâches techniques', $release->findTicketByKey('CCS-8')->getSection()?->getTitle());
        self::assertSame(['Nouvelle recherche multicritère', 'Corrections', 'Tâches techniques'], array_map(static fn ($s) => $s->getTitle(), $release->getSections()->toArray()));

        // Seconde actualisation : aucun doublon.
        $report = $this->service(ReleaseRefreshService::class)->apply($release, true, false);
        self::assertSame(0, $report['added']);
        self::assertCount(6, $release->getTickets());
    }

    public function testExcludedTicketIsNotAddedBackAndDetachOptionIsExplicit(): void
    {
        $project = $this->createProject();
        $issues = [JiraFixtures::issue('CCS-4', 'Bug', 'A'), JiraFixtures::issue('CCS-5', 'Bug', 'B')];
        $this->fakeJiraCloud($issues);
        $release = $this->service(ReleaseImportService::class)->import($project, '10042');
        $this->service(ReleaseSectionService::class)->excludeTicket($release->findTicketByKey('CCS-4'));

        $issues = [JiraFixtures::issue('CCS-4', 'Bug', 'A')];
        $report = $this->service(ReleaseRefreshService::class)->apply($release, true, true);

        self::assertSame(0, $report['added']);
        self::assertTrue($release->findTicketByKey('CCS-4')->isExcluded());
        $ccs5 = $release->findTicketByKey('CCS-5');
        self::assertSame(TicketSyncState::Inaccessible, $ccs5->getSyncState());
        self::assertNull($ccs5->getSection(), 'Détaché car l\'option a été explicitement cochée');
    }

    public function testLabelOrEpicChangedInJiraReclassifiesAutomaticallyGroupedTicketsOnly(): void
    {
        $project = $this->createProject();
        $this->em->persist((new LabelMapping())->setLabel('gestion-client')->setTitle('Gestion des clients'));
        $this->em->persist((new LabelMapping())->setLabel('facturation')->setTitle('Facturation'));
        $this->em->flush();
        $epic = JiraFixtures::parent('CCS-1', 'Recherche');
        $issues = [
            JiraFixtures::issue('CCS-1', 'Epic', 'Recherche', [], '10042', true),
            JiraFixtures::issue('CCS-2', 'Story', 'Filtrer', ['parent' => $epic]),
            JiraFixtures::issue('CCS-4', 'Story', 'Fiche client', ['labels' => ['gestion-client']]),
            JiraFixtures::issue('CCS-5', 'Bug', 'Purge', ['labels' => ['batch-nuit']]),
            JiraFixtures::issue('CCS-8', 'Bug', 'Relance', ['labels' => ['batch-nuit']]),
            JiraFixtures::issue('CCS-6', 'Bug', 'Libellé'),
            JiraFixtures::issue('CCS-7', 'Bug', 'Bouton'),
        ];
        $this->fakeJiraCloud($issues);
        $release = $this->service(ReleaseImportService::class)->import($project, '10042');
        self::assertSame(['Recherche', 'Gestion des clients', 'batch-nuit', 'Corrections'], array_map(static fn ($s) => $s->getTitle(), $release->getSections()->toArray()));
        [$epicSection, $clientSection, $batchSection, $fixSection] = $release->getSections()->toArray();
        $batchSection->setFunctionalContent('Texte rédigé');
        // Classement manuel : CCS-6 placé dans la section d'Epic.
        $this->service(ReleaseSectionService::class)->moveTicket($release->findTicketByKey('CCS-6'), $epicSection);

        // Dans Jira : étiquettes et Epic modifiés.
        $issues[2]['fields']['labels'] = ['facturation'];          // CCS-4 : gestion-client → facturation
        $issues[3]['fields']['labels'] = [];                        // CCS-5 : plus d'étiquette → par type
        $issues[4]['fields']['parent'] = $epic;                     // CCS-8 : rattaché à l'Epic
        $issues[5]['fields']['labels'] = ['gestion-client'];        // CCS-6 : classé à la main, ne bouge pas
        $issues[6]['fields']['labels'] = ['facturation'];           // CCS-7 : rejoint la même nouvelle section

        $refresh = $this->service(ReleaseRefreshService::class);
        $plan = $refresh->plan($release);
        $moves = array_filter($plan->regroupings, static fn ($r) => $r['automatic']);
        self::assertSame(['CCS-4', 'CCS-5', 'CCS-8', 'CCS-7'], array_values(array_map(static fn ($r) => $r['ticket']->getJiraKey(), $moves)));
        self::assertSame(['CCS-6'], array_values(array_map(static fn ($r) => $r['ticket']->getJiraKey(), array_filter($plan->regroupings, static fn ($r) => !$r['automatic']))));
        self::assertSame(['Facturation'], array_values(array_map(static fn ($p) => $p->title, $plan->newSections)));

        $report = $refresh->apply($release);
        self::assertSame(4, $report['regroupings']);
        self::assertSame(1, $report['sections_removed']);

        $this->em->clear();
        $release = $this->em->find(Release::class, $release->getId());
        $sectionOf = static fn (string $key) => $release->findTicketByKey($key)?->getSection()?->getTitle();
        self::assertSame('Facturation', $sectionOf('CCS-4'));
        self::assertSame('Corrections', $sectionOf('CCS-5'));
        self::assertSame('Recherche', $sectionOf('CCS-8'));
        self::assertSame('Recherche', $sectionOf('CCS-6'));
        self::assertSame('Facturation', $sectionOf('CCS-7'));
        self::assertSame('Recherche', $sectionOf('CCS-1'));
        // « Gestion des clients » vidée sans texte → supprimée ; « batch-nuit » vidée mais rédigée → conservée.
        self::assertSame(['Recherche', 'batch-nuit', 'Corrections', 'Facturation'], array_map(static fn ($s) => $s->getTitle(), $release->getSections()->toArray()));

        // Seconde actualisation : plus rien à reclasser.
        self::assertSame([], $this->service(ReleaseRefreshService::class)->plan($release)->regroupings);
    }

    public function testReclassificationCanBeDisabled(): void
    {
        $project = $this->createProject();
        $issues = [JiraFixtures::issue('CCS-4', 'Bug', 'A', ['labels' => ['ancien']])];
        $this->fakeJiraCloud($issues);
        $release = $this->service(ReleaseImportService::class)->import($project, '10042');
        $issues[0]['fields']['labels'] = ['nouveau'];

        $report = $this->service(ReleaseRefreshService::class)->apply($release, true, false, false);

        self::assertSame(0, $report['regroupings']);
        self::assertSame('ancien', $release->findTicketByKey('CCS-4')->getSection()?->getTitle());
        self::assertSame(['nouveau'], $release->findTicketByKey('CCS-4')->getLabels());
    }
}
