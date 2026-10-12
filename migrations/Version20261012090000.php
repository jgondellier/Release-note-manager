<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schéma initial du Release Note Manager (MySQL 8 / MariaDB 10.4+).
 *
 * Tables : external_connection, project, issue_type_mapping, release_note,
 * release_section, jira_ticket, publication, publication_attempt.
 * Insère également les correspondances globales « type Jira → section » par défaut.
 */
final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma initial : connexions, projets, releases, sections, tickets Jira, publications Confluence + correspondances de types par défaut';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('CREATE TABLE external_connection (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, provider VARCHAR(20) NOT NULL, deployment_type VARCHAR(20) NOT NULL, base_url VARCHAR(255) NOT NULL, authentication_type VARCHAR(20) NOT NULL, username VARCHAR(180) DEFAULT NULL, encrypted_secret LONGTEXT DEFAULT NULL, jira_epic_link_field VARCHAR(64) DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, last_tested_at DATETIME DEFAULT NULL, last_test_status VARCHAR(20) DEFAULT NULL, last_test_message VARCHAR(1000) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_connection_provider_type (provider, deployment_type), UNIQUE INDEX uniq_connection_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE issue_type_mapping (id INT AUTO_INCREMENT NOT NULL, jira_issue_type VARCHAR(100) NOT NULL, section_title VARCHAR(150) NOT NULL, category VARCHAR(20) NOT NULL, position INT DEFAULT 100 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT DEFAULT NULL, UNIQUE INDEX uniq_mapping_project_type (project_id, jira_issue_type), INDEX IDX_B01E6FDB166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE jira_ticket (id INT AUTO_INCREMENT NOT NULL, jira_issue_id VARCHAR(50) NOT NULL, jira_key VARCHAR(50) NOT NULL, issue_type VARCHAR(100) NOT NULL, summary VARCHAR(500) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(100) DEFAULT NULL, status_category VARCHAR(30) DEFAULT NULL, priority VARCHAR(100) DEFAULT NULL, epic_key VARCHAR(50) DEFAULT NULL, epic_name VARCHAR(255) DEFAULT NULL, parent_key VARCHAR(50) DEFAULT NULL, components JSON NOT NULL, labels JSON NOT NULL, fix_versions JSON NOT NULL, jira_url VARCHAR(500) NOT NULL, raw_data JSON DEFAULT NULL, position INT NOT NULL, sync_state VARCHAR(30) NOT NULL, excluded TINYINT DEFAULT 0 NOT NULL, added_manually TINYINT DEFAULT 0 NOT NULL, jira_updated_at DATETIME DEFAULT NULL, last_synced_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, release_id INT NOT NULL, section_id INT DEFAULT NULL, INDEX idx_ticket_section_position (section_id, position), INDEX idx_ticket_epic (epic_key), UNIQUE INDEX uniq_ticket_release_key (release_id, jira_key), UNIQUE INDEX uniq_ticket_release_issue (release_id, jira_issue_id), INDEX IDX_C5E58EC2B12A727D (release_id), INDEX IDX_C5E58EC2D823E37A (section_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, code VARCHAR(50) NOT NULL, description LONGTEXT DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, jira_project_key VARCHAR(50) NOT NULL, confluence_cloud_space_key VARCHAR(100) DEFAULT NULL, confluence_cloud_parent_page_id VARCHAR(50) DEFAULT NULL, confluence_server_space_key VARCHAR(100) DEFAULT NULL, confluence_server_parent_page_id VARCHAR(50) DEFAULT NULL, last_checked_at DATETIME DEFAULT NULL, last_check_result JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, jira_connection_id INT DEFAULT NULL, confluence_cloud_connection_id INT DEFAULT NULL, confluence_server_connection_id INT DEFAULT NULL, UNIQUE INDEX uniq_project_code (code), INDEX IDX_2FB3D0EE503E1FD8 (jira_connection_id), INDEX IDX_2FB3D0EEF7B94F79 (confluence_cloud_connection_id), INDEX IDX_2FB3D0EE9610CCDD (confluence_server_connection_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE publication (id INT AUTO_INCREMENT NOT NULL, format VARCHAR(20) NOT NULL, target VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, space_key VARCHAR(100) DEFAULT NULL, page_title VARCHAR(255) DEFAULT NULL, confluence_page_id VARCHAR(50) DEFAULT NULL, confluence_page_url VARCHAR(500) DEFAULT NULL, confluence_page_version INT DEFAULT NULL, content_hash VARCHAR(64) DEFAULT NULL, error_message VARCHAR(1000) DEFAULT NULL, attempt_count INT DEFAULT 0 NOT NULL, started_at DATETIME DEFAULT NULL, last_attempt_at DATETIME DEFAULT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, release_id INT NOT NULL, INDEX idx_publication_status (status), UNIQUE INDEX uniq_publication_target (release_id, format, target), INDEX IDX_AF3C6779B12A727D (release_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE publication_attempt (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, action VARCHAR(20) NOT NULL, message VARCHAR(1000) DEFAULT NULL, page_version INT DEFAULT NULL, duration_ms INT DEFAULT NULL, created_at DATETIME NOT NULL, publication_id INT NOT NULL, INDEX idx_attempt_created (created_at), INDEX IDX_1065D45A38B217A7 (publication_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE release_note (id INT AUTO_INCREMENT NOT NULL, jira_version_id VARCHAR(50) NOT NULL, jira_version_name VARCHAR(255) NOT NULL, jira_version_description LONGTEXT DEFAULT NULL, jira_version_released TINYINT DEFAULT 0 NOT NULL, jira_version_release_date DATE DEFAULT NULL, title VARCHAR(255) NOT NULL, release_date DATE DEFAULT NULL, status VARCHAR(30) NOT NULL, it_intro LONGTEXT DEFAULT NULL, functional_intro LONGTEXT DEFAULT NULL, communication_title VARCHAR(255) DEFAULT NULL, communication_intro LONGTEXT DEFAULT NULL, useful_links LONGTEXT DEFAULT NULL, last_synced_at DATETIME DEFAULT NULL, last_sync_report JSON DEFAULT NULL, last_sync_error VARCHAR(1000) DEFAULT NULL, archived_at DATETIME DEFAULT NULL, status_before_archive VARCHAR(30) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT NOT NULL, INDEX idx_release_status (status), UNIQUE INDEX uniq_release_project_version (project_id, jira_version_id), INDEX IDX_824A4A03166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE release_section (id INT AUTO_INCREMENT NOT NULL, source_type VARCHAR(20) NOT NULL, source_key VARCHAR(100) DEFAULT NULL, category VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, it_content LONGTEXT DEFAULT NULL, risks LONGTEXT DEFAULT NULL, validation LONGTEXT DEFAULT NULL, functional_title VARCHAR(255) DEFAULT NULL, functional_content LONGTEXT DEFAULT NULL, communication_title VARCHAR(255) DEFAULT NULL, communication_content LONGTEXT DEFAULT NULL, impact LONGTEXT DEFAULT NULL, action_required LONGTEXT DEFAULT NULL, audience VARCHAR(255) DEFAULT NULL, links LONGTEXT DEFAULT NULL, visible_it TINYINT DEFAULT 1 NOT NULL, visible_functional TINYINT DEFAULT 1 NOT NULL, visible_communication TINYINT DEFAULT 1 NOT NULL, functional_show_tickets TINYINT DEFAULT 0 NOT NULL, delivery_confirmed TINYINT DEFAULT 0 NOT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, release_id INT NOT NULL, INDEX idx_section_release_position (release_id, position), INDEX idx_section_source (release_id, source_type, source_key), INDEX IDX_F3060650B12A727D (release_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE issue_type_mapping ADD CONSTRAINT FK_B01E6FDB166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE jira_ticket ADD CONSTRAINT FK_C5E58EC2B12A727D FOREIGN KEY (release_id) REFERENCES release_note (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE jira_ticket ADD CONSTRAINT FK_C5E58EC2D823E37A FOREIGN KEY (section_id) REFERENCES release_section (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE503E1FD8 FOREIGN KEY (jira_connection_id) REFERENCES external_connection (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EEF7B94F79 FOREIGN KEY (confluence_cloud_connection_id) REFERENCES external_connection (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE9610CCDD FOREIGN KEY (confluence_server_connection_id) REFERENCES external_connection (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE publication ADD CONSTRAINT FK_AF3C6779B12A727D FOREIGN KEY (release_id) REFERENCES release_note (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE publication_attempt ADD CONSTRAINT FK_1065D45A38B217A7 FOREIGN KEY (publication_id) REFERENCES publication (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE release_note ADD CONSTRAINT FK_824A4A03166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE release_section ADD CONSTRAINT FK_F3060650B12A727D FOREIGN KEY (release_id) REFERENCES release_note (id) ON DELETE CASCADE');
        // Correspondances globales par défaut (modifiables dans Paramètres > Types de tickets).
        $defaults = [
            ['Bug', 'Corrections', 'fix', 20],
            ['Bogue', 'Corrections', 'fix', 20],
            ['Anomalie', 'Corrections', 'fix', 20],
            ['Story', 'Évolutions', 'feature', 10],
            ['Récit', 'Évolutions', 'feature', 10],
            ['Improvement', 'Évolutions', 'feature', 10],
            ['Amélioration', 'Évolutions', 'feature', 10],
            ['New Feature', 'Évolutions', 'feature', 10],
            ['Nouvelle fonctionnalité', 'Évolutions', 'feature', 10],
            ['Task', 'Tâches techniques', 'technical', 30],
            ['Tâche', 'Tâches techniques', 'technical', 30],
            ['Sub-task', 'Tâches techniques', 'technical', 30],
            ['Sous-tâche', 'Tâches techniques', 'technical', 30],
            ['*', 'Autres changements', 'other', 90],
        ];
        foreach ($defaults as [$type, $title, $category, $position]) {
            $this->addSql(
                'INSERT INTO issue_type_mapping (project_id, jira_issue_type, section_title, category, position, created_at, updated_at) VALUES (NULL, ?, ?, ?, ?, NOW(), NOW())',
                [$type, $title, $category, $position]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');

        $this->addSql('ALTER TABLE issue_type_mapping DROP FOREIGN KEY FK_B01E6FDB166D1F9C');
        $this->addSql('ALTER TABLE jira_ticket DROP FOREIGN KEY FK_C5E58EC2B12A727D');
        $this->addSql('ALTER TABLE jira_ticket DROP FOREIGN KEY FK_C5E58EC2D823E37A');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE503E1FD8');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EEF7B94F79');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE9610CCDD');
        $this->addSql('ALTER TABLE publication DROP FOREIGN KEY FK_AF3C6779B12A727D');
        $this->addSql('ALTER TABLE publication_attempt DROP FOREIGN KEY FK_1065D45A38B217A7');
        $this->addSql('ALTER TABLE release_note DROP FOREIGN KEY FK_824A4A03166D1F9C');
        $this->addSql('ALTER TABLE release_section DROP FOREIGN KEY FK_F3060650B12A727D');
        $this->addSql('DROP TABLE external_connection');
        $this->addSql('DROP TABLE issue_type_mapping');
        $this->addSql('DROP TABLE jira_ticket');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE publication');
        $this->addSql('DROP TABLE publication_attempt');
        $this->addSql('DROP TABLE release_note');
        $this->addSql('DROP TABLE release_section');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
