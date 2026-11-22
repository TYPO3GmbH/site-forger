<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The forced runs and queued jobs are gone since the deltas keep the index complete, but their columns stayed.
 */
final class Version20261124110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the column forced of command_run and job_queue';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('ALTER TABLE command_run DROP forced');
        $this->addSql('ALTER TABLE job_queue DROP forced');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('ALTER TABLE command_run ADD forced TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE job_queue ADD forced TINYINT(1) DEFAULT 0 NOT NULL');
    }
}
