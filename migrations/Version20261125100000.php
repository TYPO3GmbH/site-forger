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
 * A sprint board sets the order of its cards: of its issues, or of the changes on a board of reviews.
 */
final class Version20261125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add card_sort and review_sort to sprint_board';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql("ALTER TABLE sprint_board ADD card_sort VARCHAR(30) DEFAULT 'updated' NOT NULL");
        $this->addSql("ALTER TABLE sprint_board ADD review_sort VARCHAR(30) DEFAULT 'least-updated' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('ALTER TABLE sprint_board DROP card_sort, DROP review_sort');
    }
}
