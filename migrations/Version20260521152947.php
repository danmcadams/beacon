<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260521152947 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_settings ADD COLUMN notification_lead_minutes CLOB NOT NULL DEFAULT \'[15,10,5]\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__app_settings AS SELECT id, timezone FROM app_settings');
        $this->addSql('DROP TABLE app_settings');
        $this->addSql('CREATE TABLE app_settings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, timezone VARCHAR(50) NOT NULL)');
        $this->addSql('INSERT INTO app_settings (id, timezone) SELECT id, timezone FROM __temp__app_settings');
        $this->addSql('DROP TABLE __temp__app_settings');
    }
}
