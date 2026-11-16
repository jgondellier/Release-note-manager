<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mises à jour de l'application depuis Git : paramètres (une ligne) et historique des exécutions.
 */
final class Version20261116144554 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création des tables local_app et local_update_run (mises à jour de l\'application)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('CREATE TABLE local_app (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, provider VARCHAR(20) NOT NULL, repository VARCHAR(500) NOT NULL, server_url VARCHAR(255) DEFAULT NULL, ref VARCHAR(255) NOT NULL, token VARCHAR(500) DEFAULT NULL, subdirectory VARCHAR(255) DEFAULT NULL, preserved_paths LONGTEXT NOT NULL, commands LONGTEXT NOT NULL, environment LONGTEXT DEFAULT NULL, delete_obsolete TINYINT NOT NULL, backup TINYINT NOT NULL, stop_on_error TINYINT NOT NULL, deployed_files JSON DEFAULT NULL, last_run_at DATETIME DEFAULT NULL, last_status VARCHAR(20) DEFAULT NULL, last_version VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE local_update_run (id INT AUTO_INCREMENT NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, status VARCHAR(20) NOT NULL, ref VARCHAR(255) NOT NULL, version VARCHAR(255) DEFAULT NULL, launched_from VARCHAR(20) NOT NULL, log LONGTEXT NOT NULL, summary JSON NOT NULL, options JSON NOT NULL, app_id INT NOT NULL, INDEX IDX_E53283F3D46F4E3 (started_at), INDEX IDX_E53283F37987212D (app_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE local_update_run ADD CONSTRAINT FK_E53283F37987212D FOREIGN KEY (app_id) REFERENCES local_app (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('ALTER TABLE local_update_run DROP FOREIGN KEY FK_E53283F37987212D');
        $this->addSql('DROP TABLE local_update_run');
        $this->addSql('DROP TABLE local_app');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
