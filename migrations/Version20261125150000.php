<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace DoctrineMigrations;

use App\Sprint\ReviewBoardFilter;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

/**
 * A sprint board has a type, see BoardTypeEnum: it shows issues, or reviews by filters of its own, see
 * ReviewBoardFilter. A board of issues sets whether it opens with its issues or with their reviews. The review
 * board that was a page of its own turns into the boards "Bugfixes", "Tasks" and "Features" of the category
 * "Reviews".
 *
 * It uses ReviewBoardFilter, as only it knows the query of the filters. Should ReviewBoardFilter change, a board
 * writes its query again when it is saved.
 */
final class Version20261125150000 extends AbstractMigration
{
    private const string CATEGORY = 'reviews';

    private const string INSERT_BOARD = <<<'SQL'
        INSERT INTO sprint_board (category_id, name, identifier, uuid, type, query, review_filter, sorting)
        SELECT c.id, ?, ?, UUID(), 'reviews', ?, ?, ? FROM sprint_board_category c WHERE c.identifier = ?
        SQL;

    /** @var list<string> */
    private array $identifiers = [];

    public function getDescription(): string
    {
        return 'Add type, review_filter and first_view to sprint_board, turn the review board into boards';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql("ALTER TABLE sprint_board ADD type VARCHAR(10) DEFAULT 'issues' NOT NULL");
        $this->addSql('ALTER TABLE sprint_board ADD review_filter JSON DEFAULT NULL');
        $this->addSql("ALTER TABLE sprint_board ADD first_view VARCHAR(10) DEFAULT 'issues' NOT NULL");

        $this->identifiers = array_map(
            strval(...),
            $this->connection->fetchFirstColumn('SELECT identifier FROM sprint_board'),
        );
        $this->addSql(
            'INSERT INTO sprint_board_category (name, identifier, icon)'
            . " SELECT 'Reviews', ?, 'actions-code-pull-request' FROM DUAL"
            . ' WHERE NOT EXISTS (SELECT 1 FROM sprint_board_category WHERE identifier = ?)',
            [self::CATEGORY, self::CATEGORY],
        );
        $sorting = 0;
        foreach (['BUGFIX' => 'Bugfixes', 'TASK' => 'Tasks', 'FEATURE' => 'Features'] as $tag => $name) {
            $filter = ReviewBoardFilter::fromArray(['tags' => [$tag]]);
            $this->addBoard($name, strtolower($name), $filter, ++$sorting);
            $this->write(sprintf('The review board of the %s is now the board "%s"', strtolower($name), $name));
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql("DELETE FROM sprint_board WHERE type = 'reviews'");
        $this->addSql('ALTER TABLE sprint_board DROP type, DROP review_filter, DROP first_view');
    }

    /**
     * Adds a board of reviews to the category "Reviews". Its identifier is the one wished for, with "-2", "-3" and
     * so on while a board has it.
     */
    private function addBoard(string $name, string $identifier, ReviewBoardFilter $filter, int $sorting): void
    {
        $free = $identifier;
        for ($number = 2; in_array($free, $this->identifiers, true); ++$number) {
            $free = $identifier . '-' . $number;
        }
        $this->identifiers[] = $free;
        $this->addSql(self::INSERT_BOARD, [
            $name,
            $free,
            Yaml::dump($filter->toQuery(), 10, 2),
            json_encode($filter->toArray(), JSON_THROW_ON_ERROR),
            $sorting,
            self::CATEGORY,
        ]);
    }
}
