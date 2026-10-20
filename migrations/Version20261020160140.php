<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Couverture de tests Octane (extension Jira octane-coverage) : activation et espace Octane par projet.
 */
final class Version20261020160140 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout des paramètres de couverture Octane sur project';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('ALTER TABLE project ADD octane_coverage_enabled TINYINT DEFAULT 0 NOT NULL, ADD octane_workspace_config_id VARCHAR(100) DEFAULT NULL, ADD octane_workspace_id VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('ALTER TABLE project DROP octane_coverage_enabled, DROP octane_workspace_config_id, DROP octane_workspace_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
