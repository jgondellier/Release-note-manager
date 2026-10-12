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

        foreach (PublicationFormat::cases() as $format) {
            $storage = $renderer->renderStorage($release, $format);
            $renderer->assertWellFormed($storage);
            self::assertStringNotContainsString('<script', $storage);
            self::assertStringNotContainsString('class="', $storage, 'Pas de CSS navigateur dans le format Confluence');
        }
        self::assertStringContainsString('ac:name="status"', $renderer->renderStorage($release, PublicationFormat::It));
        self::assertSame('CCS 2.4.0 — Release note IT', $renderer->pageTitle($release, PublicationFormat::It));
    }
}
