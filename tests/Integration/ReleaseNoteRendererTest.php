<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\PublicationFormat;
use App\Service\ReleaseNote\ReleaseImportService;
use App\Service\ReleaseNote\ReleaseNoteRenderer;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\JiraFixtures;

final class ReleaseNoteRendererTest extends DatabaseTestCase
{
    public function testThreeFormatsAreRenderedWithTheirOwnWording(): void
    {
        $project = $this->createProject();
        $issues = [
            JiraFixtures::issue('CCS-1', 'Epic', 'Recherche', [], '10042', true),
            JiraFixtures::issue('CCS-2', 'Story', 'Filtrer <les> dossiers', ['parent' => JiraFixtures::parent('CCS-1', 'Recherche')]),
            JiraFixtures::issue('CCS-4', 'Bug', 'Date erronée', ['status' => ['name' => 'En cours', 'statusCategory' => ['key' => 'indeterminate']]]),
        ];
        $this->fakeJiraCloud($issues);
        $release = $this->service(ReleaseImportService::class)->import($project, '10042');
        $release->setItIntro('Intro IT')->setFunctionalIntro('Intro fonctionnelle')->setCommunicationIntro('Intro com & co');
        [$epic, $fixes] = $release->getSections()->toArray();
        $epic->setItContent('Index **SQL** ajouté')->setRisks('Charge base')->setFunctionalContent('Parcours simplifié')
            ->setCommunicationContent('Trouvez vos dossiers plus vite')->setImpact('Écran réorganisé')->setActionRequired('Former les équipes');
        $fixes->setFunctionalContent('Correction de dates')->setCommunicationTitle('Corrections diverses');
        $this->em->flush();

        /** @var ReleaseNoteRenderer $renderer */
        $renderer = $this->service(ReleaseNoteRenderer::class);

        $it = $renderer->renderWeb($release, PublicationFormat::It);
        self::assertStringContainsString('CCS-2', $it, 'Le format IT expose les clés Jira');
        self::assertStringContainsString('Filtrer &lt;les&gt; dossiers', $it, 'Échappement des données Jira');
        self::assertStringContainsString('<strong>SQL</strong>', $it);
        self::assertStringContainsString('Risques et points de vigilance', $it);
        self::assertStringNotContainsString('Tests et validations', $it, 'Aucune validation inventée');

        $functional = $renderer->renderWeb($release, PublicationFormat::Functional);
        self::assertStringContainsString('Parcours simplifié', $functional);
        self::assertStringNotContainsString('CCS-2', $functional, 'Le format fonctionnel masque les clés Jira');
        self::assertStringContainsString('Former les équipes', $functional);

        $communication = $renderer->renderWeb($release, PublicationFormat::Communication);
        self::assertStringContainsString('Trouvez vos dossiers plus vite', $communication);
        self::assertStringNotContainsString('CCS-', $communication);
        self::assertStringContainsString('Intro com &amp; co', $communication);
        // CCS-4 non terminé : la section n'est pas présentée comme disponible.
        self::assertStringContainsString('En cours de finalisation', $communication);

        foreach (PublicationFormat::releaseNoteFormats() as $format) {
            $storage = $renderer->renderStorage($release, $format);
            $renderer->assertWellFormed($storage);
            self::assertStringNotContainsString('<script', $storage);
            self::assertStringNotContainsString('class="', $storage, 'Pas de CSS navigateur dans le format Confluence');
            self::assertStringNotContainsString('&lt;p&gt;', $storage, 'Contenu des encadrés non ré-échappé');
        }
        self::assertStringContainsString('ac:name="status"', $renderer->renderStorage($release, PublicationFormat::It));
        self::assertSame('CCS 2.4.0 — Release note IT', $renderer->pageTitle($release, PublicationFormat::It));
    }

    public function testFunctionalSummaryCountsTicketsByTheirOwnTypeNotSections(): void
    {
        $project = $this->createProject();
        $epic = JiraFixtures::parent('CCS-1', 'Recherche');
        $issues = [
            JiraFixtures::issue('CCS-1', 'Epic', 'Recherche', [], '10042', true),
            JiraFixtures::issue('CCS-2', 'Story', 'Filtrer', ['parent' => $epic]),
            JiraFixtures::issue('CCS-3', 'Story', 'Exporter', ['parent' => $epic]),
            JiraFixtures::issue('CCS-4', 'Bug', 'Bug de la recherche', ['parent' => $epic]),
            JiraFixtures::issue('CCS-5', 'Bug', 'Date erronée'),
            JiraFixtures::issue('CCS-6', 'Bug', 'Libellé faux'),
        ];
        $this->fakeJiraCloud($issues);
        $release = $this->service(ReleaseImportService::class)->import($project, '10042');
        // 2 sections (1 Epic « évolution » + 1 « Corrections ») mais 2 stories et 3 bugs.
        self::assertCount(2, $release->getSections());

        $renderer = $this->service(ReleaseNoteRenderer::class);
        $web = $renderer->renderWeb($release, PublicationFormat::Functional);
        self::assertStringContainsString('<strong>2</strong> évolutions fonctionnelles', $web);
        self::assertStringContainsString('<strong>3</strong> corrections', $web, 'Le bug rattaché à l\'Epic compte comme correction');
        $storage = $renderer->renderStorage($release, PublicationFormat::Functional);
        self::assertStringContainsString('<ac:parameter ac:name="title">2 évolutions fonctionnelles</ac:parameter>', $storage);
        self::assertStringContainsString('<ac:parameter ac:name="title">3 corrections</ac:parameter>', $storage);
    }

    public function testCommunicationUsesTheSharedDesignTokens(): void
    {
        $project = $this->createProject();
        $issues = [JiraFixtures::issue('CCS-2', 'Story', 'Filtrer'), JiraFixtures::issue('CCS-5', 'Bug', 'Date erronée')];
        $this->fakeJiraCloud($issues);
        $release = $this->service(ReleaseImportService::class)->import($project, '10042');
        foreach ($release->getSections() as $section) {
            $section->setCommunicationContent('Texte ' . $section->getTitle())->setImpact('Gain de temps');
        }
        $this->em->flush();

        $renderer = $this->service(ReleaseNoteRenderer::class);
        $web = $renderer->renderWeb($release, PublicationFormat::Communication);
        self::assertStringContainsString('--ds-primary: ' . \App\Service\ReleaseNote\Design\CommunicationTheme::BRAND['primary'], $web, 'Jetons posés sur la racine');
        self::assertStringContainsString('class="ds-tag ds-tag-feature"', $web);
        self::assertStringContainsString('Ce qui change pour vous', $web);
        self::assertMatchesRegularExpression('#<span class="ds-kpi-value">1</span><span class="ds-kpi-label">nouveauté à découvrir#', $web);

        $storage = $renderer->renderStorage($release, PublicationFormat::Communication);
        $renderer->assertWellFormed($storage);
        $feature = \App\Service\ReleaseNote\Design\CommunicationTheme::CATEGORIES['feature'];
        self::assertStringContainsString('<ac:parameter ac:name="borderColor">' . $feature['accent'] . '</ac:parameter>', $storage, 'Même couleur de catégorie que le web');
        self::assertStringContainsString('<ac:parameter ac:name="titleBGColor">' . \App\Service\ReleaseNote\Design\CommunicationTheme::BRAND['primaryStrong'] . '</ac:parameter>', $storage);
        self::assertStringNotContainsString('class="', $storage);
    }
}
