<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Monthly hours on organization (included hours, overage tolerance, last
 * month the client was emailed) and removal of the unused package column.
 *
 * Done in place with ALTER TABLE ADD/DROP COLUMN (SQLite >= 3.35) rather than
 * the table rebuild Doctrine generates: organization is referenced by foreign
 * keys, and dropping it would hit the rows that point to it.
 */
final class Version20261120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add organization monthly hours, overage tolerance and notified month; drop package';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization ADD COLUMN monthly_hours NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE organization ADD COLUMN overage_tolerance INTEGER DEFAULT 30 NOT NULL');
        $this->addSql('ALTER TABLE organization ADD COLUMN quota_exceeded_notified_month VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE organization DROP COLUMN package');
    }

    public function down(Schema $schema): void
    {
        // Package values are not restored
        $this->addSql('ALTER TABLE organization ADD COLUMN package VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE organization DROP COLUMN quota_exceeded_notified_month');
        $this->addSql('ALTER TABLE organization DROP COLUMN overage_tolerance');
        $this->addSql('ALTER TABLE organization DROP COLUMN monthly_hours');
    }
}
