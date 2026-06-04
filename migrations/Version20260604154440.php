<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260604154440 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add jira_boards (a project has many boards) and jira_ticket_cache.board_id. Backfills one board per project from the legacy jira_board_id/jira_jql_filter fields and points existing tickets at it. The legacy projects columns are retained for now.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE jira_boards (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_key VARCHAR(50) NOT NULL, label VARCHAR(100) DEFAULT NULL, jql_filter CLOB DEFAULT NULL, sort_order INTEGER NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_A1AB6E72166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_A1AB6E72166D1F9C ON jira_boards (project_id)');

        // One board per existing project, from the legacy single-board fields.
        $this->addSql('INSERT INTO jira_boards (project_key, jql_filter, sort_order, project_id) SELECT jira_board_id, jira_jql_filter, 0, id FROM projects');

        $this->addSql('CREATE TEMPORARY TABLE __temp__jira_ticket_cache AS SELECT id, ticket_key, summary, status, status_category, priority, assignee, due_date, jira_updated_at, raw_json, fetched_at, project_id, first_seen_at, jira_created_at FROM jira_ticket_cache');
        $this->addSql('DROP TABLE jira_ticket_cache');
        $this->addSql('CREATE TABLE jira_ticket_cache (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, ticket_key VARCHAR(50) NOT NULL, summary CLOB NOT NULL, status VARCHAR(50) NOT NULL, status_category VARCHAR(20) NOT NULL, priority VARCHAR(20) DEFAULT NULL, assignee VARCHAR(100) DEFAULT NULL, due_date DATE DEFAULT NULL, jira_updated_at DATETIME NOT NULL, raw_json CLOB NOT NULL, fetched_at DATETIME NOT NULL, project_id INTEGER NOT NULL, first_seen_at DATETIME DEFAULT NULL, jira_created_at DATETIME DEFAULT NULL, board_id INTEGER DEFAULT NULL, CONSTRAINT FK_EF4CD396166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_EF4CD396E7EC5785 FOREIGN KEY (board_id) REFERENCES jira_boards (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO jira_ticket_cache (id, ticket_key, summary, status, status_category, priority, assignee, due_date, jira_updated_at, raw_json, fetched_at, project_id, first_seen_at, jira_created_at) SELECT id, ticket_key, summary, status, status_category, priority, assignee, due_date, jira_updated_at, raw_json, fetched_at, project_id, first_seen_at, jira_created_at FROM __temp__jira_ticket_cache');
        $this->addSql('DROP TABLE __temp__jira_ticket_cache');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_ticket_key ON jira_ticket_cache (project_id, ticket_key)');
        $this->addSql('CREATE INDEX IDX_EF4CD396166D1F9C ON jira_ticket_cache (project_id)');
        $this->addSql('CREATE INDEX IDX_EF4CD396E7EC5785 ON jira_ticket_cache (board_id)');

        // Point existing tickets at their project's (single) board.
        $this->addSql('UPDATE jira_ticket_cache SET board_id = (SELECT b.id FROM jira_boards b WHERE b.project_id = jira_ticket_cache.project_id ORDER BY b.sort_order LIMIT 1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__jira_ticket_cache AS SELECT id, ticket_key, summary, status, status_category, priority, assignee, due_date, jira_updated_at, jira_created_at, raw_json, fetched_at, first_seen_at, project_id FROM jira_ticket_cache');
        $this->addSql('DROP TABLE jira_ticket_cache');
        $this->addSql('CREATE TABLE jira_ticket_cache (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, ticket_key VARCHAR(50) NOT NULL, summary CLOB NOT NULL, status VARCHAR(50) NOT NULL, status_category VARCHAR(20) NOT NULL, priority VARCHAR(20) DEFAULT NULL, assignee VARCHAR(100) DEFAULT NULL, due_date DATE DEFAULT NULL, jira_updated_at DATETIME NOT NULL, jira_created_at DATETIME DEFAULT NULL, raw_json CLOB NOT NULL, fetched_at DATETIME NOT NULL, first_seen_at DATETIME DEFAULT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_EF4CD396166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO jira_ticket_cache (id, ticket_key, summary, status, status_category, priority, assignee, due_date, jira_updated_at, jira_created_at, raw_json, fetched_at, first_seen_at, project_id) SELECT id, ticket_key, summary, status, status_category, priority, assignee, due_date, jira_updated_at, jira_created_at, raw_json, fetched_at, first_seen_at, project_id FROM __temp__jira_ticket_cache');
        $this->addSql('DROP TABLE __temp__jira_ticket_cache');
        $this->addSql('CREATE INDEX IDX_EF4CD396166D1F9C ON jira_ticket_cache (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_ticket_key ON jira_ticket_cache (project_id, ticket_key)');

        $this->addSql('DROP TABLE jira_boards');
    }
}
