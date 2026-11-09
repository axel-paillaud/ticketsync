<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds ON DELETE rules to foreign keys (notifications, comments, attachments,
 * activities, invitations, assigned tickets).
 *
 * SQLite cannot alter foreign keys, so the tables are rebuilt (copy, drop,
 * recreate). This MUST run with foreign keys disabled: otherwise dropping
 * "ticket" performs an implicit DELETE that cascades to the already rebuilt
 * child tables and wipes their rows. "PRAGMA foreign_keys" is a no-op inside
 * a transaction, so the migration is not transactional and manages its own
 * transaction after disabling foreign keys.
 */
final class Version20261109120847 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ON DELETE rules to foreign keys (SQLite table rebuild)';
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

        // Data is copied as-is, so existing violations would survive the rebuild
        // and make later writes fail once foreign keys are enforced.
        $violations = $this->connection->fetchAllAssociative('PRAGMA foreign_key_check');
        $this->abortIf(
            $violations !== [],
            'Foreign key violations found, clean them up first: '.json_encode($violations)
        );
    }

    private function beginRebuild(): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN TRANSACTION');
    }

    private function endRebuild(): void
    {
        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function up(Schema $schema): void
    {
        $this->beginRebuild();

        $this->addSql('CREATE TEMPORARY TABLE __temp__activity AS SELECT id, description, hours, work_date, created_at, updated_at, ticket_id, created_by_id, organization_id FROM activity');
        $this->addSql('DROP TABLE activity');
        $this->addSql('CREATE TABLE activity (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, description CLOB NOT NULL, hours NUMERIC(5, 2) NOT NULL, work_date DATE NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, ticket_id INTEGER NOT NULL, created_by_id INTEGER NOT NULL, organization_id INTEGER NOT NULL, CONSTRAINT FK_AC74095AB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AC74095A32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AC74095A700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO activity (id, description, hours, work_date, created_at, updated_at, ticket_id, created_by_id, organization_id) SELECT id, description, hours, work_date, created_at, updated_at, ticket_id, created_by_id, organization_id FROM __temp__activity');
        $this->addSql('DROP TABLE __temp__activity');
        $this->addSql('CREATE INDEX IDX_AC74095A32C8A3DE ON activity (organization_id)');
        $this->addSql('CREATE INDEX IDX_AC74095AB03A8386 ON activity (created_by_id)');
        $this->addSql('CREATE INDEX IDX_AC74095A700047D2 ON activity (ticket_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__attachment AS SELECT id, filename, stored_filename, mime_type, size, uploaded_at, ticket_id, comment_id, uploaded_by_id FROM attachment');
        $this->addSql('DROP TABLE attachment');
        $this->addSql('CREATE TABLE attachment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INTEGER NOT NULL, uploaded_at DATETIME NOT NULL, ticket_id INTEGER DEFAULT NULL, comment_id INTEGER DEFAULT NULL, uploaded_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_795FD9BB700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_795FD9BBF8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_795FD9BBA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO attachment (id, filename, stored_filename, mime_type, size, uploaded_at, ticket_id, comment_id, uploaded_by_id) SELECT id, filename, stored_filename, mime_type, size, uploaded_at, ticket_id, comment_id, uploaded_by_id FROM __temp__attachment');
        $this->addSql('DROP TABLE __temp__attachment');
        $this->addSql('CREATE INDEX IDX_795FD9BB700047D2 ON attachment (ticket_id)');
        $this->addSql('CREATE INDEX IDX_795FD9BBF8697D13 ON attachment (comment_id)');
        $this->addSql('CREATE INDEX IDX_795FD9BBA2B28FE8 ON attachment (uploaded_by_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__comment AS SELECT id, content, created_at, updated_at, ticket_id, author_id FROM comment');
        $this->addSql('DROP TABLE comment');
        $this->addSql('CREATE TABLE comment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, content CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, ticket_id INTEGER NOT NULL, author_id INTEGER NOT NULL, CONSTRAINT FK_9474526CF675F31B FOREIGN KEY (author_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9474526C700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO comment (id, content, created_at, updated_at, ticket_id, author_id) SELECT id, content, created_at, updated_at, ticket_id, author_id FROM __temp__comment');
        $this->addSql('DROP TABLE __temp__comment');
        $this->addSql('CREATE INDEX IDX_9474526CF675F31B ON comment (author_id)');
        $this->addSql('CREATE INDEX IDX_9474526C700047D2 ON comment (ticket_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__notification AS SELECT id, type, is_read, created_at, user_id, ticket_id, triggered_by_id FROM notification');
        $this->addSql('DROP TABLE notification');
        $this->addSql('CREATE TABLE notification (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(50) NOT NULL, is_read BOOLEAN NOT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, ticket_id INTEGER DEFAULT NULL, triggered_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_BF5476CA700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_BF5476CA63C5923F FOREIGN KEY (triggered_by_id) REFERENCES user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO notification (id, type, is_read, created_at, user_id, ticket_id, triggered_by_id) SELECT id, type, is_read, created_at, user_id, ticket_id, triggered_by_id FROM __temp__notification');
        $this->addSql('DROP TABLE __temp__notification');
        $this->addSql('CREATE INDEX IDX_BF5476CA63C5923F ON notification (triggered_by_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA700047D2 ON notification (ticket_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CAA76ED395 ON notification (user_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__ticket AS SELECT id, title, description, created_at, updated_at, organization_id, created_by_id, assigned_to_id, status_id, priority_id FROM ticket');
        $this->addSql('DROP TABLE ticket');
        $this->addSql('CREATE TABLE ticket (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, organization_id INTEGER NOT NULL, created_by_id INTEGER NOT NULL, assigned_to_id INTEGER DEFAULT NULL, status_id INTEGER NOT NULL, priority_id INTEGER NOT NULL, CONSTRAINT FK_97A0ADA332C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_97A0ADA3B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_97A0ADA36BF700BD FOREIGN KEY (status_id) REFERENCES status (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_97A0ADA3497B19F9 FOREIGN KEY (priority_id) REFERENCES priority (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_97A0ADA3F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO ticket (id, title, description, created_at, updated_at, organization_id, created_by_id, assigned_to_id, status_id, priority_id) SELECT id, title, description, created_at, updated_at, organization_id, created_by_id, assigned_to_id, status_id, priority_id FROM __temp__ticket');
        $this->addSql('DROP TABLE __temp__ticket');
        $this->addSql('CREATE INDEX IDX_97A0ADA3497B19F9 ON ticket (priority_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA36BF700BD ON ticket (status_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA3F4BD7827 ON ticket (assigned_to_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA3B03A8386 ON ticket (created_by_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA332C8A3DE ON ticket (organization_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user_invitation AS SELECT id, token, expires_at, created_at, accepted_at, user_id FROM user_invitation');
        $this->addSql('DROP TABLE user_invitation');
        $this->addSql('CREATE TABLE user_invitation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, token VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, accepted_at DATETIME DEFAULT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_567AA74EA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO user_invitation (id, token, expires_at, created_at, accepted_at, user_id) SELECT id, token, expires_at, created_at, accepted_at, user_id FROM __temp__user_invitation');
        $this->addSql('DROP TABLE __temp__user_invitation');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_567AA74EA76ED395 ON user_invitation (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_567AA74E5F37A13B ON user_invitation (token)');

        $this->endRebuild();
    }

    public function down(Schema $schema): void
    {
        $this->beginRebuild();

        $this->addSql('CREATE TEMPORARY TABLE __temp__activity AS SELECT id, description, hours, work_date, created_at, updated_at, ticket_id, created_by_id, organization_id FROM activity');
        $this->addSql('DROP TABLE activity');
        $this->addSql('CREATE TABLE activity (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, description CLOB NOT NULL, hours NUMERIC(5, 2) NOT NULL, work_date DATE NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, ticket_id INTEGER NOT NULL, created_by_id INTEGER NOT NULL, organization_id INTEGER NOT NULL, CONSTRAINT FK_AC74095AB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AC74095A32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AC74095A700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO activity (id, description, hours, work_date, created_at, updated_at, ticket_id, created_by_id, organization_id) SELECT id, description, hours, work_date, created_at, updated_at, ticket_id, created_by_id, organization_id FROM __temp__activity');
        $this->addSql('DROP TABLE __temp__activity');
        $this->addSql('CREATE INDEX IDX_AC74095A700047D2 ON activity (ticket_id)');
        $this->addSql('CREATE INDEX IDX_AC74095AB03A8386 ON activity (created_by_id)');
        $this->addSql('CREATE INDEX IDX_AC74095A32C8A3DE ON activity (organization_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__attachment AS SELECT id, filename, stored_filename, mime_type, size, uploaded_at, ticket_id, comment_id, uploaded_by_id FROM attachment');
        $this->addSql('DROP TABLE attachment');
        $this->addSql('CREATE TABLE attachment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INTEGER NOT NULL, uploaded_at DATETIME NOT NULL, ticket_id INTEGER DEFAULT NULL, comment_id INTEGER DEFAULT NULL, uploaded_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_795FD9BB700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_795FD9BBF8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_795FD9BBA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO attachment (id, filename, stored_filename, mime_type, size, uploaded_at, ticket_id, comment_id, uploaded_by_id) SELECT id, filename, stored_filename, mime_type, size, uploaded_at, ticket_id, comment_id, uploaded_by_id FROM __temp__attachment');
        $this->addSql('DROP TABLE __temp__attachment');
        $this->addSql('CREATE INDEX IDX_795FD9BB700047D2 ON attachment (ticket_id)');
        $this->addSql('CREATE INDEX IDX_795FD9BBF8697D13 ON attachment (comment_id)');
        $this->addSql('CREATE INDEX IDX_795FD9BBA2B28FE8 ON attachment (uploaded_by_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__comment AS SELECT id, content, created_at, updated_at, ticket_id, author_id FROM comment');
        $this->addSql('DROP TABLE comment');
        $this->addSql('CREATE TABLE comment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, content CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, ticket_id INTEGER NOT NULL, author_id INTEGER NOT NULL, CONSTRAINT FK_9474526CF675F31B FOREIGN KEY (author_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9474526C700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO comment (id, content, created_at, updated_at, ticket_id, author_id) SELECT id, content, created_at, updated_at, ticket_id, author_id FROM __temp__comment');
        $this->addSql('DROP TABLE __temp__comment');
        $this->addSql('CREATE INDEX IDX_9474526C700047D2 ON comment (ticket_id)');
        $this->addSql('CREATE INDEX IDX_9474526CF675F31B ON comment (author_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__notification AS SELECT id, type, is_read, created_at, user_id, ticket_id, triggered_by_id FROM notification');
        $this->addSql('DROP TABLE notification');
        $this->addSql('CREATE TABLE notification (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(50) NOT NULL, is_read BOOLEAN NOT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, ticket_id INTEGER DEFAULT NULL, triggered_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_BF5476CA700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_BF5476CA63C5923F FOREIGN KEY (triggered_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO notification (id, type, is_read, created_at, user_id, ticket_id, triggered_by_id) SELECT id, type, is_read, created_at, user_id, ticket_id, triggered_by_id FROM __temp__notification');
        $this->addSql('DROP TABLE __temp__notification');
        $this->addSql('CREATE INDEX IDX_BF5476CAA76ED395 ON notification (user_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA700047D2 ON notification (ticket_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA63C5923F ON notification (triggered_by_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__ticket AS SELECT id, title, description, created_at, updated_at, organization_id, created_by_id, assigned_to_id, status_id, priority_id FROM ticket');
        $this->addSql('DROP TABLE ticket');
        $this->addSql('CREATE TABLE ticket (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, organization_id INTEGER NOT NULL, created_by_id INTEGER NOT NULL, assigned_to_id INTEGER DEFAULT NULL, status_id INTEGER NOT NULL, priority_id INTEGER NOT NULL, CONSTRAINT FK_97A0ADA332C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_97A0ADA3B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_97A0ADA36BF700BD FOREIGN KEY (status_id) REFERENCES status (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_97A0ADA3497B19F9 FOREIGN KEY (priority_id) REFERENCES priority (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_97A0ADA3F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO ticket (id, title, description, created_at, updated_at, organization_id, created_by_id, assigned_to_id, status_id, priority_id) SELECT id, title, description, created_at, updated_at, organization_id, created_by_id, assigned_to_id, status_id, priority_id FROM __temp__ticket');
        $this->addSql('DROP TABLE __temp__ticket');
        $this->addSql('CREATE INDEX IDX_97A0ADA332C8A3DE ON ticket (organization_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA3B03A8386 ON ticket (created_by_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA3F4BD7827 ON ticket (assigned_to_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA36BF700BD ON ticket (status_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA3497B19F9 ON ticket (priority_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user_invitation AS SELECT id, token, expires_at, created_at, accepted_at, user_id FROM user_invitation');
        $this->addSql('DROP TABLE user_invitation');
        $this->addSql('CREATE TABLE user_invitation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, token VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, accepted_at DATETIME DEFAULT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_567AA74EA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO user_invitation (id, token, expires_at, created_at, accepted_at, user_id) SELECT id, token, expires_at, created_at, accepted_at, user_id FROM __temp__user_invitation');
        $this->addSql('DROP TABLE __temp__user_invitation');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_567AA74E5F37A13B ON user_invitation (token)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_567AA74EA76ED395 ON user_invitation (user_id)');

        $this->endRebuild();
    }
}
