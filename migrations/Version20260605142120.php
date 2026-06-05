<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260605142120 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete orphaned cached tickets left behind by past project deletions (before FK enforcement was enabled).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM jira_ticket_cache WHERE project_id NOT IN (SELECT id FROM projects)');
    }

    public function down(Schema $schema): void
    {
        // Orphan rows cannot be reconstructed; nothing to revert.
    }
}
