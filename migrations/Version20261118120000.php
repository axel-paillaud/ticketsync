<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds activity.org_id: the org-mode entry an activity was synced from (MCP log_activity).
 *
 * A nullable column can be added in place with ALTER TABLE, so unlike the
 * migrations that change existing columns, no SQLite table rebuild is needed.
 */
final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add activity.org_id (org-mode sync key)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activity ADD COLUMN org_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AC74095AF4837C1B ON activity (org_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_AC74095AF4837C1B');
        $this->addSql('ALTER TABLE activity DROP COLUMN org_id');
    }
}
