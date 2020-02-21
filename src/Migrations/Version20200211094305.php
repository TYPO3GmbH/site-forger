<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20200211094305 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE sprint_board_category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, identifier VARCHAR(255) NOT NULL, icon VARCHAR(255) NOT NULL, sorting INT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE backport_blacklist_entry (id INT AUTO_INCREMENT NOT NULL, change_id VARCHAR(255) NOT NULL, comment VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sprint_board (id INT AUTO_INCREMENT NOT NULL, category_id INT NOT NULL, name VARCHAR(255) NOT NULL, query LONGTEXT NOT NULL, identifier VARCHAR(255) NOT NULL, sorting INT DEFAULT NULL, INDEX IDX_6AB5850B12469DE2 (category_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE employment_time (id INT AUTO_INCREMENT NOT NULL, company_id INT NOT NULL, employed_from DATETIME NOT NULL, employed_to DATETIME DEFAULT NULL, account VARCHAR(255) NOT NULL, INDEX IDX_491F395C979B1AD6 (company_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE company (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE sprint_board ADD CONSTRAINT FK_6AB5850B12469DE2 FOREIGN KEY (category_id) REFERENCES sprint_board_category (id)');
        $this->addSql('ALTER TABLE employment_time ADD CONSTRAINT FK_491F395C979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id)');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE sprint_board DROP FOREIGN KEY FK_6AB5850B12469DE2');
        $this->addSql('ALTER TABLE employment_time DROP FOREIGN KEY FK_491F395C979B1AD6');
        $this->addSql('DROP TABLE sprint_board_category');
        $this->addSql('DROP TABLE backport_blacklist_entry');
        $this->addSql('DROP TABLE sprint_board');
        $this->addSql('DROP TABLE employment_time');
        $this->addSql('DROP TABLE company');
    }
}
