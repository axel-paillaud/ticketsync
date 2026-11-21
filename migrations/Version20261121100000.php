<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Scope of API tokens: "tma" (/mcp, existing tokens) or "notes" (/mcp/notes).
 */
final class Version20261121100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_token scope';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE api_token ADD COLUMN scope VARCHAR(20) DEFAULT 'tma' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP COLUMN scope');
    }
}
