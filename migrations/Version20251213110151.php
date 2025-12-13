<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251213110151 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE time_entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, description CLOB NOT NULL, hours NUMERIC(5, 2) NOT NULL, billed_hours NUMERIC(5, 2) NOT NULL, hourly_rate_snapshot NUMERIC(10, 2) NOT NULL, billed_amount NUMERIC(10, 2) NOT NULL, work_date DATE NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, ticket_id INTEGER NOT NULL, created_by_id INTEGER NOT NULL, organization_id INTEGER NOT NULL, CONSTRAINT FK_6E537C0C700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6E537C0CB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6E537C0C32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_6E537C0C700047D2 ON time_entry (ticket_id)');
        $this->addSql('CREATE INDEX IDX_6E537C0CB03A8386 ON time_entry (created_by_id)');
        $this->addSql('CREATE INDEX IDX_6E537C0C32C8A3DE ON time_entry (organization_id)');
        $this->addSql('ALTER TABLE organization ADD COLUMN hourly_rate NUMERIC(10, 2) DEFAULT \'80.00\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE time_entry');
        $this->addSql('CREATE TEMPORARY TABLE __temp__organization AS SELECT id, name, slug, is_active, created_at, email, phone, address FROM organization');
        $this->addSql('DROP TABLE organization');
        $this->addSql('CREATE TABLE organization (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(128) NOT NULL, is_active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, address CLOB DEFAULT NULL)');
        $this->addSql('INSERT INTO organization (id, name, slug, is_active, created_at, email, phone, address) SELECT id, name, slug, is_active, created_at, email, phone, address FROM __temp__organization');
        $this->addSql('DROP TABLE __temp__organization');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C1EE637C989D9B62 ON organization (slug)');
    }
}
