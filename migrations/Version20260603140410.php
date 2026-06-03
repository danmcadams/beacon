<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260603140410 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add jira_created_at to jira_ticket_cache. The "new" highlight is now based on Jira\'s created date. Nullable: rows fetched before this column stay NULL until the next refresh repopulates them.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jira_ticket_cache ADD COLUMN jira_created_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__jira_ticket_cache AS SELECT id, ticket_key, summary, status, status_category, priority, assignee, due_date, jira_updated_at, raw_json, fetched_at, first_seen_at, project_id FROM jira_ticket_cache');
        $this->addSql('DROP TABLE jira_ticket_cache');
        $this->addSql('CREATE TABLE jira_ticket_cache (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, ticket_key VARCHAR(50) NOT NULL, summary CLOB NOT NULL, status VARCHAR(50) NOT NULL, status_category VARCHAR(20) NOT NULL, priority VARCHAR(20) DEFAULT NULL, assignee VARCHAR(100) DEFAULT NULL, due_date DATE DEFAULT NULL, jira_updated_at DATETIME NOT NULL, raw_json CLOB NOT NULL, fetched_at DATETIME NOT NULL, first_seen_at DATETIME DEFAULT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_EF4CD396166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO jira_ticket_cache (id, ticket_key, summary, status, status_category, priority, assignee, due_date, jira_updated_at, raw_json, fetched_at, first_seen_at, project_id) SELECT id, ticket_key, summary, status, status_category, priority, assignee, due_date, jira_updated_at, raw_json, fetched_at, first_seen_at, project_id FROM __temp__jira_ticket_cache');
        $this->addSql('DROP TABLE __temp__jira_ticket_cache');
        $this->addSql('CREATE INDEX IDX_EF4CD396166D1F9C ON jira_ticket_cache (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_ticket_key ON jira_ticket_cache (project_id, ticket_key)');
    }
}
