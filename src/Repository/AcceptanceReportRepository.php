<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcceptanceReport;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AcceptanceReport>
 */
class AcceptanceReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcceptanceReport::class);
    }

    /** Dernier PV du projet : sert de modèle pour préremplir le suivant. */
    public function findLatestForProject(Project $project): ?AcceptanceReport
    {
        return $this->createQueryBuilder('a')
            ->join('a.release', 'r')
            ->andWhere('r.project = :project')
            ->setParameter('project', $project)
            ->orderBy('a.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
