<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251219203802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE activity (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, description CLOB NOT NULL, hours NUMERIC(5, 2) NOT NULL, work_date DATE NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, ticket_id INTEGER NOT NULL, created_by_id INTEGER NOT NULL, organization_id INTEGER NOT NULL, CONSTRAINT FK_AC74095A700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AC74095AB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AC74095A32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_AC74095A700047D2 ON activity (ticket_id)');
        $this->addSql('CREATE INDEX IDX_AC74095AB03A8386 ON activity (created_by_id)');
        $this->addSql('CREATE INDEX IDX_AC74095A32C8A3DE ON activity (organization_id)');
        $this->addSql('DROP TABLE time_entry');
        $this->addSql('CREATE TEMPORARY TABLE __temp__organization AS SELECT id, name, slug, is_active, created_at, email, phone, address, siret FROM organization');
        $this->addSql('DROP TABLE organization');
        $this->addSql('CREATE TABLE organization (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(128) NOT NULL, is_active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, address CLOB DEFAULT NULL, siret VARCHAR(14) DEFAULT NULL)');
        $this->addSql('INSERT INTO organization (id, name, slug, is_active, created_at, email, phone, address, siret) SELECT id, name, slug, is_active, created_at, email, phone, address, siret FROM __temp__organization');
        $this->addSql('DROP TABLE __temp__organization');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C1EE637C989D9B62 ON organization (slug)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, email, roles, password, created_at, first_name, last_name, is_verified, organization_id FROM user');
        $this->addSql('DROP TABLE user');
        $this->addSql('CREATE TABLE user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, first_name VARCHAR(100) DEFAULT NULL, last_name VARCHAR(100) DEFAULT NULL, is_verified BOOLEAN NOT NULL, organization_id INTEGER NOT NULL, CONSTRAINT FK_8D93D64932C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO user (id, email, roles, password, created_at, first_name, last_name, is_verified, organization_id) SELECT id, email, roles, password, created_at, first_name, last_name, is_verified, organization_id FROM __temp__user');
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON user (email)');
        $this->addSql('CREATE INDEX IDX_8D93D64932C8A3DE ON user (organization_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE time_entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, description CLOB NOT NULL COLLATE "BINARY", hours NUMERIC(5, 2) NOT NULL, billed_hours NUMERIC(5, 2) NOT NULL, hourly_rate_snapshot NUMERIC(10, 2) NOT NULL, billed_amount NUMERIC(10, 2) NOT NULL, work_date DATE NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, ticket_id INTEGER NOT NULL, created_by_id INTEGER NOT NULL, organization_id INTEGER NOT NULL, CONSTRAINT FK_6E537C0C700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6E537C0CB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6E537C0C32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_6E537C0C32C8A3DE ON time_entry (organization_id)');
        $this->addSql('CREATE INDEX IDX_6E537C0CB03A8386 ON time_entry (created_by_id)');
        $this->addSql('CREATE INDEX IDX_6E537C0C700047D2 ON time_entry (ticket_id)');
        $this->addSql('DROP TABLE activity');
        $this->addSql('ALTER TABLE organization ADD COLUMN hourly_rate NUMERIC(10, 2) DEFAULT \'80.00\' NOT NULL');
        $this->addSql('ALTER TABLE user ADD COLUMN monthly_alert_threshold DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD COLUMN alert_threshold_enabled BOOLEAN DEFAULT 0 NOT NULL');
    }
}
