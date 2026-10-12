<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /** @return list<Project> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.jiraConnection', 'j')->addSelect('j')
            ->orderBy('p.active', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<Project> */
    public function findActive(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = true')
            ->orderBy('p.name', 'ASC')
            ->getQuery()->getResult();
    }
}
