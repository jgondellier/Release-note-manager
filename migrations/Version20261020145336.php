<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Page parente Confluence dédiée aux PV de recette (Cloud et Server/DC), par projet.
 */
final class Version20261020145336 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout des pages parentes dédiées aux PV de recette sur project';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('ALTER TABLE project ADD confluence_cloud_acceptance_parent_page_id VARCHAR(50) DEFAULT NULL, ADD confluence_server_acceptance_parent_page_id VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('ALTER TABLE project DROP confluence_cloud_acceptance_parent_page_id, DROP confluence_server_acceptance_parent_page_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
