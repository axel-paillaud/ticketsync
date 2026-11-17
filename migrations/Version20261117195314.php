<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117195314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create api_token table (MCP authentication)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_token (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, owner_id INTEGER NOT NULL, CONSTRAINT FK_7BA2F5EB7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7BA2F5EBB3BC57DA ON api_token (token_hash)');
        $this->addSql('CREATE INDEX IDX_7BA2F5EB7E3C61F9 ON api_token (owner_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE api_token');
    }
}
