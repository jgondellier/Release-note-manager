<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Release;
use App\Enum\ReleaseStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Release>
 */
class ReleaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Release::class);
    }

    /** @return list<Release> */
    public function findLatest(int $limit = 8): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.project', 'p')->addSelect('p')
            ->andWhere('r.status != :archived')
            ->setParameter('archived', ReleaseStatus::Archived)
            ->orderBy('r.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * @param list<ReleaseStatus> $statuses
     *
     * @return list<Release>
     */
    public function findByStatuses(array $statuses, int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.project', 'p')->addSelect('p')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('statuses', array_map(static fn (ReleaseStatus $s) => $s->value, $statuses))
            ->orderBy('r.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<Release> */
    public function findWithSyncErrors(int $limit = 10): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.project', 'p')->addSelect('p')
            ->andWhere('r.lastSyncError IS NOT NULL')
            ->andWhere('r.status != :archived')
            ->setParameter('archived', ReleaseStatus::Archived)
            ->orderBy('r.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<Release> */
    public function search(?Project $project, ?ReleaseStatus $status, bool $includeArchived): array
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.project', 'p')->addSelect('p')
            ->orderBy('r.updatedAt', 'DESC');
        if (null !== $project) {
            $qb->andWhere('r.project = :project')->setParameter('project', $project);
        }
        if (null !== $status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        } elseif (!$includeArchived) {
            $qb->andWhere('r.status != :archived')->setParameter('archived', ReleaseStatus::Archived);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByProjectAndVersion(Project $project, string $jiraVersionId): ?Release
    {
        return $this->findOneBy(['project' => $project, 'jiraVersionId' => $jiraVersionId]);
    }

    /** @return array<string, int> nombre de releases par statut */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.status AS status, COUNT(r.id) AS total')
            ->groupBy('r.status')
            ->getQuery()->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof ReleaseStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['total'];
        }

        return $counts;
    }
}
