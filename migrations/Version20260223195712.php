<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260223195712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE notification (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(50) NOT NULL, is_read BOOLEAN NOT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, ticket_id INTEGER DEFAULT NULL, triggered_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_BF5476CA700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_BF5476CA63C5923F FOREIGN KEY (triggered_by_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_BF5476CAA76ED395 ON notification (user_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA700047D2 ON notification (ticket_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA63C5923F ON notification (triggered_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE notification');
    }
}
