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

final class Version20261120120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the runs of the GitHub imports, which no longer exist: the command runs page would show them as overdue';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on MySQL or MariaDB.');

        $this->addSql("DELETE FROM command_run WHERE job LIKE 'app:import --mode=github%' OR job LIKE 'app:import %--mode=github%'");
    }

    public function down(Schema $schema): void
    {
        // The runs are a log, nothing to bring back
    }
}
