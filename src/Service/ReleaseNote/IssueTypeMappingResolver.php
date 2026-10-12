<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

use App\Entity\Project;
use App\Repository\IssueTypeMappingRepository;
use App\Service\ReleaseNote\Dto\IssueTypeRuleSet;

class IssueTypeMappingResolver
{
    public function __construct(private readonly IssueTypeMappingRepository $repository)
    {
    }

    public function forProject(?Project $project): IssueTypeRuleSet
    {
        return IssueTypeRuleSet::fromMappings($this->repository->findApplicableTo($project));
    }
}
