<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PV de recette (procès-verbal de tests système) : un par release, avec instantané Jira.
 */
final class Version20261020135016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de la table acceptance_report (PV de recette par release)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('CREATE TABLE acceptance_report (id INT AUTO_INCREMENT NOT NULL, document_title VARCHAR(255) NOT NULL, document_status VARCHAR(20) NOT NULL, application_name VARCHAR(255) NOT NULL, code VARCHAR(50) DEFAULT NULL, version_label VARCHAR(255) NOT NULL, mep_date DATE DEFAULT NULL, subject LONGTEXT DEFAULT NULL, acceptance_managers LONGTEXT DEFAULT NULL, delivery_managers LONGTEXT DEFAULT NULL, phases JSON NOT NULL, scope_intro LONGTEXT DEFAULT NULL, out_of_scope LONGTEXT DEFAULT NULL, test_comment_prefix VARCHAR(30) NOT NULL, anomalies_comment LONGTEXT DEFAULT NULL, decision VARCHAR(30) DEFAULT NULL, reserves LONGTEXT DEFAULT NULL, comments LONGTEXT DEFAULT NULL, delivery_signatory VARCHAR(255) DEFAULT NULL, delivery_signed_at DATE DEFAULT NULL, acceptance_signatory VARCHAR(255) DEFAULT NULL, acceptance_signed_at DATE DEFAULT NULL, jira_snapshot JSON DEFAULT NULL, jira_synced_at DATETIME DEFAULT NULL, jira_sync_error VARCHAR(1000) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, release_id INT NOT NULL, UNIQUE INDEX uniq_acceptance_release (release_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE acceptance_report ADD CONSTRAINT FK_E8417DA8B12A727D FOREIGN KEY (release_id) REFERENCES release_note (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('ALTER TABLE acceptance_report DROP FOREIGN KEY FK_E8417DA8B12A727D');
        $this->addSql('DROP TABLE acceptance_report');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
