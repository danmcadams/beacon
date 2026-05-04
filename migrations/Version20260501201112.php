<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260501201112 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE briefings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, briefing_date DATE NOT NULL, content_markdown CLOB NOT NULL, tickets_snapshot CLOB NOT NULL, status VARCHAR(20) NOT NULL, error_message CLOB DEFAULT NULL, model VARCHAR(50) NOT NULL, input_tokens INTEGER DEFAULT NULL, output_tokens INTEGER DEFAULT NULL, generated_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_CD5BBD16166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_CD5BBD16166D1F9C ON briefings (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_briefing_date_project ON briefings (briefing_date, project_id)');
        $this->addSql('CREATE TABLE jira_ticket_cache (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, ticket_key VARCHAR(50) NOT NULL, summary CLOB NOT NULL, status VARCHAR(50) NOT NULL, status_category VARCHAR(20) NOT NULL, priority VARCHAR(20) DEFAULT NULL, assignee VARCHAR(100) DEFAULT NULL, due_date DATE DEFAULT NULL, jira_updated_at DATETIME NOT NULL, raw_json CLOB NOT NULL, fetched_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_EF4CD396166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_EF4CD396166D1F9C ON jira_ticket_cache (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_ticket_key ON jira_ticket_cache (project_id, ticket_key)');
        $this->addSql('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description CLOB DEFAULT NULL, status VARCHAR(20) NOT NULL, jira_base_url VARCHAR(255) NOT NULL, jira_board_id VARCHAR(50) NOT NULL, jira_jql_filter CLOB DEFAULT NULL, daily_hours_target NUMERIC(3, 1) NOT NULL, color VARCHAR(7) NOT NULL, sort_order INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE TABLE quick_links (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, label VARCHAR(100) NOT NULL, url VARCHAR(500) NOT NULL, sort_order INTEGER NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_8926169C166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_8926169C166D1F9C ON quick_links (project_id)');
        $this->addSql('CREATE TABLE schedule_blocks (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, day_of_week SMALLINT NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, label VARCHAR(100) NOT NULL, block_type VARCHAR(20) NOT NULL, sort_order INTEGER NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE briefings');
        $this->addSql('DROP TABLE jira_ticket_cache');
        $this->addSql('DROP TABLE projects');
        $this->addSql('DROP TABLE quick_links');
        $this->addSql('DROP TABLE schedule_blocks');
    }
}
