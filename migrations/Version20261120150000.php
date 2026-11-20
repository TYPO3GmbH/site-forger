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

final class Version20261120150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add job_queue and job_pause for the jobs of the schedule, and to command_run the start of the phase, the request to stop, the steps of a run and whether it was forced';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on MySQL or MariaDB.');

        $this->addSql('CREATE TABLE job_queue (id INT AUTO_INCREMENT NOT NULL, job VARCHAR(255) NOT NULL, queued_by VARCHAR(255) NOT NULL, queued_at DATETIME NOT NULL, forced TINYINT(1) DEFAULT 0 NOT NULL, UNIQUE INDEX job_queue_job (job), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE job_pause (id INT AUTO_INCREMENT NOT NULL, job VARCHAR(255) NOT NULL, paused_by VARCHAR(255) NOT NULL, reason VARCHAR(255) NOT NULL, paused_at DATETIME NOT NULL, UNIQUE INDEX job_pause_job (job), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE command_run ADD phase_started_at DATETIME DEFAULT NULL, ADD stop_status VARCHAR(20) DEFAULT NULL, ADD stop_reason VARCHAR(255) DEFAULT NULL, ADD steps JSON DEFAULT NULL, ADD forced TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on MySQL or MariaDB.');

        $this->addSql('DROP TABLE job_queue');
        $this->addSql('DROP TABLE job_pause');
        $this->addSql('ALTER TABLE command_run DROP phase_started_at, DROP stop_status, DROP stop_reason, DROP steps, DROP forced');
    }
}
