<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table de conversion des étiquettes Jira (regroupement des tickets sans Epic).
 */
final class Version20261027141140 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création de la table label_mapping';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('CREATE TABLE label_mapping (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, title VARCHAR(255) DEFAULT NULL, category VARCHAR(20) DEFAULT NULL, ignored TINYINT DEFAULT 0 NOT NULL, position INT DEFAULT 100 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT DEFAULT NULL, UNIQUE INDEX uniq_label_mapping_project_label (project_id, label), INDEX IDX_F56785A3166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE label_mapping ADD CONSTRAINT FK_F56785A3166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('ALTER TABLE label_mapping DROP FOREIGN KEY FK_F56785A3166D1F9C');
        $this->addSql('DROP TABLE label_mapping');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
