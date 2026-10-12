<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Publication;
use App\Entity\Release;
use App\Enum\PublicationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Publication>
 */
class PublicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Publication::class);
    }

    /** @return list<Publication> */
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('pub')
            ->join('pub.release', 'r')->addSelect('r')
            ->join('r.project', 'p')->addSelect('p')
            ->andWhere('pub.lastAttemptAt IS NOT NULL')
            ->orderBy('pub.lastAttemptAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<Publication> */
    public function findFailed(int $limit = 10): array
    {
        return $this->createQueryBuilder('pub')
            ->join('pub.release', 'r')->addSelect('r')
            ->join('r.project', 'p')->addSelect('p')
            ->andWhere('pub.status = :failed')
            ->setParameter('failed', PublicationStatus::Failed)
            ->orderBy('pub.lastAttemptAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<Publication> */
    public function findForRelease(Release $release): array
    {
        return $this->createQueryBuilder('pub')
            ->andWhere('pub.release = :release')
            ->setParameter('release', $release)
            ->orderBy('pub.format', 'ASC')
            ->addOrderBy('pub.target', 'ASC')
            ->getQuery()->getResult();
    }
}
