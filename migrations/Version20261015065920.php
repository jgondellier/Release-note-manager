<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pages de regroupement Confluence : une page par release et par instance,
 * sous laquelle sont publiées les trois release notes (IT, Fonctionnel, Communication).
 */
final class Version20261015065920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de la table publication_container (page de regroupement Confluence par release et par instance)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('CREATE TABLE publication_container (id INT AUTO_INCREMENT NOT NULL, target VARCHAR(20) NOT NULL, confluence_page_id VARCHAR(50) NOT NULL, confluence_page_url VARCHAR(500) NOT NULL, page_title VARCHAR(255) NOT NULL, confluence_page_version INT NOT NULL, content_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, release_id INT NOT NULL, UNIQUE INDEX uniq_container_release_target (release_id, target), INDEX IDX_DBE1EA8CB12A727D (release_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE publication_container ADD CONSTRAINT FK_DBE1EA8CB12A727D FOREIGN KEY (release_id) REFERENCES release_note (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('ALTER TABLE publication_container DROP FOREIGN KEY FK_DBE1EA8CB12A727D');
        $this->addSql('DROP TABLE publication_container');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
