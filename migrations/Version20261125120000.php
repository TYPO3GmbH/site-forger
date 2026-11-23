<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace DoctrineMigrations;

use App\Sprint\BoardFilter;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The queries of the sprint boards come from the filters of their form only. A query written by hand turns into the
 * filters it means, see BoardFilter::approximate(), when they keep all of it. A query they would change stays as it
 * is: the migration names its board and what the filters leave out, and its form shows that too when it opens.
 *
 * It uses BoardFilter, as only it knows the filters. Should BoardFilter change, the migration still turns the
 * queries of that time into the filters of then.
 */
final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Turn the queries of the sprint boards written by hand into the filters of their form';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $boards = $this->connection->fetchAllAssociative('SELECT id, name, query FROM sprint_board');
        foreach ($boards as $board) {
            $yaml = (string) $board['query'];
            if (null !== BoardFilter::fromYaml($yaml)) {
                continue;
            }
            $dropped = $this->droppedOf($yaml, $filter);
            if (null === $filter || [] !== $dropped) {
                $this->write(sprintf(
                    '<comment>Board %d "%s" keeps its query: the filters would leave out %s</comment>',
                    $board['id'],
                    $board['name'],
                    [] === $dropped ? 'all of it' : implode(', ', $dropped),
                ));
                continue;
            }
            $this->addSql('UPDATE sprint_board SET query = ? WHERE id = ?', [$filter->toYaml(), $board['id']]);
            $this->write(sprintf('Board %d "%s" now uses the filters of its form', $board['id'], $board['name']));
        }
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('The queries written by hand are gone, the filters mean the same.');
    }

    /**
     * @return list<string> what the filters leave out of the query, as YAML
     */
    private function droppedOf(string $yaml, ?BoardFilter &$filter): array
    {
        try {
            $query = Yaml::parse($yaml);
        } catch (ParseException) {
            $query = null;
        }
        if (!is_array($query)) {
            $filter = null;

            return [];
        }
        $import = BoardFilter::approximate($query);
        $filter = $import['filter'];

        return $import['dropped'];
    }
}
