<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sauvegarde de l'application : paramètres (une ligne).
 */
final class Version20261118090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création de la table backup_settings (sauvegarde de l\'application)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('CREATE TABLE backup_settings (id INT AUTO_INCREMENT NOT NULL, destination VARCHAR(500) DEFAULT NULL, paths LONGTEXT NOT NULL, include_database TINYINT NOT NULL, backups_kept INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('DROP TABLE backup_settings');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
