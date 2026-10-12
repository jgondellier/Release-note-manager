<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

use App\Entity\Release;

final class ReleaseAlreadyExistsException extends \RuntimeException
{
    public function __construct(public readonly Release $existing)
    {
        parent::__construct(sprintf('Une release note existe déjà pour la version « %s » de ce projet.', $existing->getJiraVersionName()));
    }
}
