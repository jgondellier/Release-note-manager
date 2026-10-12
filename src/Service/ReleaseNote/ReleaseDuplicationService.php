<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

use App\Entity\Release;
use App\Entity\ReleaseSection;
use App\Enum\SectionSourceType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Duplique la structure éditoriale d'une release vers une autre version Jira du même projet :
 * import des tickets de la nouvelle version, puis reprise du wording des sections
 * correspondantes (même Epic / même type) et copie des sections manuelles.
 */
class ReleaseDuplicationService
{
    private const EDITORIAL_FIELDS = [
        'Title', 'Description', 'ItContent', 'Risks', 'Validation', 'FunctionalTitle', 'FunctionalContent',
        'CommunicationTitle', 'CommunicationContent', 'Impact', 'ActionRequired', 'Audience', 'Links',
    ];

    public function __construct(
        private readonly ReleaseImportService $importService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function duplicate(Release $source, string $targetVersionId, ?string $title = null): Release
    {
        $project = $source->getProject();
        \assert(null !== $project);
        $target = $this->importService->import($project, $targetVersionId, $title);

        $target->setItIntro($source->getItIntro())
            ->setFunctionalIntro($source->getFunctionalIntro())
            ->setCommunicationIntro($source->getCommunicationIntro())
            ->setUsefulLinks($source->getUsefulLinks());

        foreach ($source->getSections() as $sourceSection) {
            if (SectionSourceType::Manual === $sourceSection->getSourceType()) {
                $copy = new ReleaseSection();
                $this->copyEditorial($sourceSection, $copy);
                $copy->setSourceType(SectionSourceType::Manual)
                    ->setCategory($sourceSection->getCategory())
                    ->setPosition($target->getNextSectionPosition());
                $target->addSection($copy);
                continue;
            }
            foreach ($target->getSections() as $targetSection) {
                if ($targetSection->getSourceType() === $sourceSection->getSourceType()
                    && null !== $sourceSection->getSourceKey()
                    && 0 === strcasecmp((string) $targetSection->getSourceKey(), $sourceSection->getSourceKey())) {
                    $this->copyEditorial($sourceSection, $targetSection);
                    $targetSection->setCategory($sourceSection->getCategory());
                }
            }
        }
        $this->em->flush();

        return $target;
    }

    private function copyEditorial(ReleaseSection $from, ReleaseSection $to): void
    {
        foreach (self::EDITORIAL_FIELDS as $field) {
            $to->{'set' . $field}($from->{'get' . $field}());
        }
        $to->setVisibleIt($from->isVisibleIt())
            ->setVisibleFunctional($from->isVisibleFunctional())
            ->setVisibleCommunication($from->isVisibleCommunication())
            ->setFunctionalShowTickets($from->isFunctionalShowTickets());
        // La confirmation de livraison n'est volontairement pas reprise.
    }
}
