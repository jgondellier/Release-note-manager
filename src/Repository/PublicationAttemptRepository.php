<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublicationAttempt;
use App\Entity\Release;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicationAttempt>
 */
class PublicationAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicationAttempt::class);
    }

    /** @return list<PublicationAttempt> */
    public function findForRelease(Release $release, int $limit = 200): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.publication', 'pub')->addSelect('pub')
            ->andWhere('pub.release = :release')
            ->setParameter('release', $release)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
