<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes ticket.description nullable (a title alone is sometimes enough).
 *
 * SQLite cannot alter a column, so the ticket table is rebuilt. Like
 * Version20261109120847, this MUST run with foreign keys disabled: dropping
 * "ticket" with foreign keys on performs an implicit DELETE that cascades to
 * comments, attachments, activities and notifications. "PRAGMA foreign_keys"
 * is a no-op inside a transaction, so the migration is not transactional and
 * manages its own transaction.
 */
final class Version20261117120000 extends AbstractMigration
{
    private const TICKET_COLUMNS = 'id, title, description, created_at, updated_at, organization_id, created_by_id, assigned_to_id, status_id, priority_id';

    public function getDescription(): string
    {
        return 'Make ticket.description nullable (SQLite table rebuild)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function preUp(Schema $schema): void
    {
        $this->assertSafeToRebuild();
    }

    public function preDown(Schema $schema): void
    {
        $this->assertSafeToRebuild();
    }

    private function assertSafeToRebuild(): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SQLitePlatform,
            'This migration is written for SQLite only.'
        );

        $violations = $this->connection->fetchAllAssociative('PRAGMA foreign_key_check');
        $this->abortIf(
            $violations !== [],
            'Foreign key violations found, clean them up first: '.json_encode($violations)
        );
    }

    public function up(Schema $schema): void
    {
        $this->rebuildTicketTable('description CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Tickets created without a description get an empty one back.
        $this->addSql("UPDATE ticket SET description = '' WHERE description IS NULL");
        $this->rebuildTicketTable('description CLOB NOT NULL');
    }

    private function rebuildTicketTable(string $descriptionDefinition): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN TRANSACTION');

        $this->addSql('CREATE TEMPORARY TABLE __temp__ticket AS SELECT '.self::TICKET_COLUMNS.' FROM ticket');
        $this->addSql('DROP TABLE ticket');
        $this->addSql('CREATE TABLE ticket (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, '.$descriptionDefinition.', created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, organization_id INTEGER NOT NULL, created_by_id INTEGER NOT NULL, assigned_to_id INTEGER DEFAULT NULL, status_id INTEGER NOT NULL, priority_id INTEGER NOT NULL, CONSTRAINT FK_97A0ADA332C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_97A0ADA3B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_97A0ADA36BF700BD FOREIGN KEY (status_id) REFERENCES status (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_97A0ADA3497B19F9 FOREIGN KEY (priority_id) REFERENCES priority (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_97A0ADA3F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO ticket ('.self::TICKET_COLUMNS.') SELECT '.self::TICKET_COLUMNS.' FROM __temp__ticket');
        $this->addSql('DROP TABLE __temp__ticket');
        $this->addSql('CREATE INDEX IDX_97A0ADA332C8A3DE ON ticket (organization_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA3B03A8386 ON ticket (created_by_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA3F4BD7827 ON ticket (assigned_to_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA36BF700BD ON ticket (status_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA3497B19F9 ON ticket (priority_id)');

        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }
}
