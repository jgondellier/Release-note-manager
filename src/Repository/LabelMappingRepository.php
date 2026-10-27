<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LabelMapping;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LabelMapping>
 */
class LabelMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LabelMapping::class);
    }

    /** @return list<LabelMapping> règles du projet puis règles globales, par position */
    public function findApplicableTo(?Project $project): array
    {
        $qb = $this->createQueryBuilder('m')->orderBy('m.position', 'ASC')->addOrderBy('m.label', 'ASC');
        if (null !== $project && null !== $project->getId()) {
            $qb->andWhere('m.project = :project OR m.project IS NULL')->setParameter('project', $project);
        } else {
            $qb->andWhere('m.project IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<LabelMapping> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.project', 'p')->addSelect('p')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('m.position', 'ASC')
            ->addOrderBy('m.label', 'ASC')
            ->getQuery()->getResult();
    }
}
