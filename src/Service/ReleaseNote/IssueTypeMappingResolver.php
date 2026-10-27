<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

use App\Entity\Project;
use App\Repository\IssueTypeMappingRepository;
use App\Repository\LabelMappingRepository;
use App\Service\ReleaseNote\Dto\IssueTypeRuleSet;
use App\Service\ReleaseNote\Dto\LabelRuleSet;

class IssueTypeMappingResolver
{
    public function __construct(
        private readonly IssueTypeMappingRepository $repository,
        private readonly LabelMappingRepository $labelRepository,
    ) {
    }

    /** Règles de types de tickets du projet, avec la table de conversion des étiquettes. */
    public function forProject(?Project $project): IssueTypeRuleSet
    {
        return IssueTypeRuleSet::fromMappings($this->repository->findApplicableTo($project))
            ->withLabels(LabelRuleSet::fromMappings($this->labelRepository->findApplicableTo($project)));
    }
}
