<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IssueTypeMapping;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IssueTypeMapping>
 */
class IssueTypeMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IssueTypeMapping::class);
    }

    /**
     * Règles applicables à un projet : règles spécifiques puis règles globales.
     *
     * @return list<IssueTypeMapping>
     */
    public function findApplicableTo(?Project $project): array
    {
        $qb = $this->createQueryBuilder('m')
            ->orderBy('m.position', 'ASC')
            ->addOrderBy('m.id', 'ASC');
        if (null !== $project && null !== $project->getId()) {
            $qb->andWhere('m.project = :project OR m.project IS NULL')->setParameter('project', $project);
        } else {
            $qb->andWhere('m.project IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<IssueTypeMapping> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.project', 'p')->addSelect('p')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('m.position', 'ASC')
            ->addOrderBy('m.jiraIssueType', 'ASC')
            ->getQuery()->getResult();
    }
}
