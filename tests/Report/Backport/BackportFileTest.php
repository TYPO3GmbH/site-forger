<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Report\Backport;

use App\Report\Backport\BackportFile;
use PHPUnit\Framework\TestCase;

final class BackportFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/forger-backports-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testAMissingOrEmptyFileHasNoBackportsYet(): void
    {
        $file = new BackportFile($this->path);
        self::assertNull($file->read());

        // As a deployment creates the shared file
        touch($this->path);
        self::assertNull($file->read());
    }

    public function testTheFileGivesBackWhatWasWritten(): void
    {
        $file = new BackportFile($this->path);
        $file->write(['I1234' => ['subject' => 'A fix']]);

        self::assertSame(['I1234' => ['subject' => 'A fix']], $file->read());
    }
}
