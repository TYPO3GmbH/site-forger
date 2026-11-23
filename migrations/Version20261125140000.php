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
 * A sprint board has a UUID for the addresses that change it, see SprintBoard::$uuid. The boards there are get one.
 */
final class Version20261125140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add uuid to sprint_board';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('ALTER TABLE sprint_board ADD uuid VARCHAR(36) DEFAULT NULL');
        // UUID() gives each row its own
        $this->addSql('UPDATE sprint_board SET uuid = UUID()');
        $this->addSql('ALTER TABLE sprint_board MODIFY uuid VARCHAR(36) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6AB5850BD17F50A6 ON sprint_board (uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('DROP INDEX UNIQ_6AB5850BD17F50A6 ON sprint_board');
        $this->addSql('ALTER TABLE sprint_board DROP uuid');
    }
}
