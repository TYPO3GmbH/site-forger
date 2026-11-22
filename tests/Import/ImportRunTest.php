<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Import;

use App\CommandRun\CommandRunRecorder;
use App\Import\ImportRun;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Output\BufferedOutput;

final class ImportRunTest extends TestCase
{
    /**
     * A step that the time of the run ends knows no total. The bar of Symfony throws for its estimate then, and two
     * imports failed by it.
     */
    public function testABarWithoutATotalAdvancesAndFinishes(): void
    {
        $output = new BufferedOutput();
        $run = $this->importRun($output);
        $bar = $run->progressBar();

        $run->advance($bar, 'Load changes of an older import again');
        $run->advance($bar, null);
        $run->finish($bar);

        self::assertSame(2, $bar->getProgress());
        self::assertStringContainsString('?', $output->fetch());
    }

    public function testABarWithATotalShowsItsEstimate(): void
    {
        $output = new BufferedOutput();
        $run = $this->importRun($output);
        $bar = $run->progressBar(4);

        $run->advance($bar, null);
        $run->finish($bar);

        self::assertStringContainsString('4/4', $output->fetch());
    }

    private function importRun(BufferedOutput $output): ImportRun
    {
        $recorder = new CommandRunRecorder($this->createStub(Connection::class), new NullLogger());

        return new ImportRun($output, $recorder, static function (): void {
        });
    }
}
