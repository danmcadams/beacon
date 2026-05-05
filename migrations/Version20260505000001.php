<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_settings table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_settings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, timezone VARCHAR(50) NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_settings');
    }
}
