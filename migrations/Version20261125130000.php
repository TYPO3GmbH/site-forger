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
 * The identifier of a sprint board is its name in its address, so the database keeps it unique, not only the form.
 * A board whose identifier another board has already gets its number added, like "sprint-7".
 */
final class Version20261125130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make the identifier of sprint_board unique';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        // The oldest board keeps its identifier
        $this->addSql(
            'UPDATE sprint_board b JOIN sprint_board older ON older.identifier = b.identifier AND older.id < b.id'
            . " SET b.identifier = CONCAT(b.identifier, '-', b.id)",
        );
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6AB5850B772E836A ON sprint_board (identifier)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('DROP INDEX UNIQ_6AB5850B772E836A ON sprint_board');
    }
}
