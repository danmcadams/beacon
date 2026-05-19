<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519132354 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE projects ADD COLUMN briefing_context CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__projects AS SELECT id, name, description, status, jira_base_url, jira_board_id, jira_jql_filter, daily_hours_target, color, sort_order, created_at, updated_at FROM projects');
        $this->addSql('DROP TABLE projects');
        $this->addSql('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description CLOB DEFAULT NULL, status VARCHAR(20) NOT NULL, jira_base_url VARCHAR(255) NOT NULL, jira_board_id VARCHAR(50) NOT NULL, jira_jql_filter CLOB DEFAULT NULL, daily_hours_target NUMERIC(3, 1) NOT NULL, color VARCHAR(7) NOT NULL, sort_order INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO projects (id, name, description, status, jira_base_url, jira_board_id, jira_jql_filter, daily_hours_target, color, sort_order, created_at, updated_at) SELECT id, name, description, status, jira_base_url, jira_board_id, jira_jql_filter, daily_hours_target, color, sort_order, created_at, updated_at FROM __temp__projects');
        $this->addSql('DROP TABLE __temp__projects');
    }
}
