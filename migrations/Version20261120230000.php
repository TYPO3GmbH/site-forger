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

final class Version20261120230000 extends AbstractMigration
{
    /**
     * The old command line and the new one of each import. Options follow in alphabetical order.
     */
    private const array RENAMED = [
        'app:import --mode=forgeFull' => 'app:import:forge --full',
        'app:import --mode=forgeDelta' => 'app:import:forge',
        'app:import --mode=reviewFull' => 'app:import:review --full',
        'app:import --mode=reviewDelta' => 'app:import:review',
    ];

    private const array TABLES = ['command_run', 'job_queue', 'job_pause'];

    public function getDescription(): string
    {
        return 'Rename the jobs of app:import to app:import:forge and app:import:review';
    }

    public function up(Schema $schema): void
    {
        $this->rename(self::RENAMED, ['--startAt=' => '--start-at=']);
    }

    public function down(Schema $schema): void
    {
        $this->rename(array_flip(self::RENAMED), ['--start-at=' => '--startAt=']);
    }

    /**
     * @param array<string, string> $commands
     * @param array<string, string> $options
     */
    private function rename(array $commands, array $options): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on MySQL or MariaDB.');

        foreach (self::TABLES as $table) {
            foreach ($commands as $old => $new) {
                $this->addSql(
                    "UPDATE {$table} SET job = CONCAT(:new, SUBSTRING(job, :length + 1)) WHERE job = :old OR job LIKE :prefix",
                    ['new' => $new, 'length' => mb_strlen($old), 'old' => $old, 'prefix' => addcslashes($old, '%_') . ' %'],
                );
            }
            foreach ($options as $old => $new) {
                $this->addSql("UPDATE {$table} SET job = REPLACE(job, :old, :new)", ['old' => $old, 'new' => $new]);
            }
        }
    }
}
