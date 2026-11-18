<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BackupSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BackupSettings>
 */
class BackupSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackupSettings::class);
    }

    /** Paramètres de sauvegarde (créés avec les valeurs par défaut au premier accès) */
    public function getSettings(): BackupSettings
    {
        $settings = $this->findOneBy([], ['id' => 'ASC']);
        if (null === $settings) {
            $settings = new BackupSettings();
            $this->getEntityManager()->persist($settings);
            $this->getEntityManager()->flush();
        }

        return $settings;
    }
}
