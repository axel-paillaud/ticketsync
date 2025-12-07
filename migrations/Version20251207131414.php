<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251207131414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__attachment AS SELECT id, filename, stored_filename, mime_type, size, uploaded_at, ticket_id, comment_id, uploaded_by_id FROM attachment');
        $this->addSql('DROP TABLE attachment');
        $this->addSql('CREATE TABLE attachment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INTEGER NOT NULL, uploaded_at DATETIME NOT NULL, ticket_id INTEGER DEFAULT NULL, comment_id INTEGER DEFAULT NULL, uploaded_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_795FD9BB700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_795FD9BBF8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_795FD9BBA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO attachment (id, filename, stored_filename, mime_type, size, uploaded_at, ticket_id, comment_id, uploaded_by_id) SELECT id, filename, stored_filename, mime_type, size, uploaded_at, ticket_id, comment_id, uploaded_by_id FROM __temp__attachment');
        $this->addSql('DROP TABLE __temp__attachment');
        $this->addSql('CREATE INDEX IDX_795FD9BBA2B28FE8 ON attachment (uploaded_by_id)');
        $this->addSql('CREATE INDEX IDX_795FD9BBF8697D13 ON attachment (comment_id)');
        $this->addSql('CREATE INDEX IDX_795FD9BB700047D2 ON attachment (ticket_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__attachment AS SELECT id, filename, stored_filename, mime_type, size, uploaded_at, ticket_id, comment_id, uploaded_by_id FROM attachment');
        $this->addSql('DROP TABLE attachment');
        $this->addSql('CREATE TABLE attachment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INTEGER NOT NULL, uploaded_at DATETIME NOT NULL, ticket_id INTEGER NOT NULL, comment_id INTEGER DEFAULT NULL, uploaded_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_795FD9BB700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_795FD9BBF8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_795FD9BBA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO attachment (id, filename, stored_filename, mime_type, size, uploaded_at, ticket_id, comment_id, uploaded_by_id) SELECT id, filename, stored_filename, mime_type, size, uploaded_at, ticket_id, comment_id, uploaded_by_id FROM __temp__attachment');
        $this->addSql('DROP TABLE __temp__attachment');
        $this->addSql('CREATE INDEX IDX_795FD9BB700047D2 ON attachment (ticket_id)');
        $this->addSql('CREATE INDEX IDX_795FD9BBF8697D13 ON attachment (comment_id)');
        $this->addSql('CREATE INDEX IDX_795FD9BBA2B28FE8 ON attachment (uploaded_by_id)');
    }
}
