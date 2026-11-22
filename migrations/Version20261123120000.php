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

final class Version20261123120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account_request for the accounts an admin wants asked from their source again';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql(
            'CREATE TABLE account_request (account VARCHAR(64) NOT NULL, requested_by VARCHAR(255) NOT NULL, '
            . 'requested_at DATETIME NOT NULL, PRIMARY KEY (account)) '
            . 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('DROP TABLE account_request');
    }
}
