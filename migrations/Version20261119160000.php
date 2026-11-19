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

final class Version20261119160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add command_run, the log of the runs of the imports and other console commands';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on MySQL or MariaDB.');

        $this->addSql('CREATE TABLE command_run (id INT AUTO_INCREMENT NOT NULL, job VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, started_at DATETIME NOT NULL, heartbeat_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, exit_code SMALLINT DEFAULT NULL, phase VARCHAR(255) DEFAULT NULL, progress_current INT DEFAULT NULL, progress_total INT DEFAULT NULL, documents INT NOT NULL, warnings INT NOT NULL, message LONGTEXT DEFAULT NULL, host VARCHAR(255) NOT NULL, pid INT NOT NULL, INDEX command_run_job (job, started_at), INDEX command_run_started (started_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on MySQL or MariaDB.');

        $this->addSql('DROP TABLE command_run');
    }
}
