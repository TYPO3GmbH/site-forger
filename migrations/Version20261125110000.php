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
 * A sprint board can be restricted to the people it is shared with, see SprintBoardVoter.
 */
final class Version20261125110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add restricted to sprint_board and the table sprint_board_member';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('ALTER TABLE sprint_board ADD restricted TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('CREATE TABLE sprint_board_member (id INT AUTO_INCREMENT NOT NULL, board_id INT NOT NULL,'
            . ' username VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL,'
            . ' INDEX IDX_49115C69E7EC5785 (board_id),'
            . ' UNIQUE INDEX sprint_board_member_unique (board_id, username), PRIMARY KEY(id))'
            . ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE sprint_board_member ADD CONSTRAINT FK_49115C69E7EC5785'
            . ' FOREIGN KEY (board_id) REFERENCES sprint_board (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('DROP TABLE sprint_board_member');
        $this->addSql('ALTER TABLE sprint_board DROP restricted');
    }
}
