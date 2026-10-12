<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExternalConnection;
use App\Enum\ConnectionProvider;
use App\Enum\ConnectionTestStatus;
use App\Enum\DeploymentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalConnection>
 */
class ExternalConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalConnection::class);
    }

    /** @return list<ExternalConnection> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.provider', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()->getResult();
    }

    /** QueryBuilder pour les listes déroulantes des formulaires projet. */
    public function createChoiceQueryBuilder(ConnectionProvider $provider, ?DeploymentType $type = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.provider = :provider')
            ->setParameter('provider', $provider)
            ->orderBy('c.name', 'ASC');
        if (null !== $type) {
            $qb->andWhere('c.deploymentType = :type')->setParameter('type', $type);
        }

        return $qb;
    }

    /** @return list<ExternalConnection> */
    public function findFailing(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.active = true')
            ->andWhere('c.lastTestStatus = :failure')
            ->setParameter('failure', ConnectionTestStatus::Failure)
            ->orderBy('c.lastTestedAt', 'DESC')
            ->getQuery()->getResult();
    }

    public function countProjectsUsing(ExternalConnection $connection): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'SELECT COUNT(p.id) FROM App\Entity\Project p
             WHERE p.jiraConnection = :c OR p.confluenceCloudConnection = :c OR p.confluenceServerConnection = :c'
        )->setParameter('c', $connection)->getSingleScalarResult();
    }
}
