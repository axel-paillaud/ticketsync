<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251128135649 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insert default Status and Priority data';
    }

    public function up(Schema $schema): void
    {
        // Status
        $this->addSql("INSERT INTO status (name, slug, is_closed, sort_order, created_at) VALUES
            ('Open', 'open', 0, 1, datetime('now')),
            ('In Progress', 'in-progress', 0, 2, datetime('now')),
            ('Resolved', 'resolved', 1, 3, datetime('now')),
            ('Closed', 'closed', 1, 4, datetime('now'))
        ");

        // Priority
        $this->addSql("INSERT INTO priority (name, level, color, created_at) VALUES
            ('Priority A', 3, '#ff0000', datetime('now')),
            ('Priority B', 2, '#ff9900', datetime('now')),
            ('Priority C', 1, '#999999', datetime('now'))
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM status WHERE slug IN ('open', 'in-progress', 'resolved', 'closed')");
        $this->addSql("DELETE FROM priority WHERE name IN ('Priority A', 'Priority B', 'Priority C')");
    }
}
