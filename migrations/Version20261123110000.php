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

final class Version20261123110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add person_link_dismissal for the suggestions an admin turned down';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql(
            'CREATE TABLE person_link_dismissal (id INT AUTO_INCREMENT NOT NULL, account VARCHAR(255) NOT NULL, '
            . 'person VARCHAR(255) NOT NULL, dismissed_by VARCHAR(255) NOT NULL, dismissed_at DATETIME NOT NULL, '
            . 'UNIQUE INDEX person_link_dismissal_pair (account, person), PRIMARY KEY (id)) '
            . 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('DROP TABLE person_link_dismissal');
    }
}
