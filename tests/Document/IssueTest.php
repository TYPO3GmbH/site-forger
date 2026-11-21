<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Document;

use App\Document\Issue;
use App\Enum\IssueMarkEnum;
use PHPUnit\Framework\TestCase;

final class IssueTest extends TestCase
{
    public function testAnOpenRegressionHasItsMark(): void
    {
        $open = ['id' => 1, 'status' => ['id' => 1, 'name' => 'New']];

        self::assertSame([IssueMarkEnum::REGRESSION], Issue::fromDocument($open + ['isregression' => true])->marks());
        self::assertSame([], Issue::fromDocument($open + ['isregression' => false])->marks());
        self::assertSame([], Issue::fromDocument($open)->marks());

        $closed = ['id' => 1, 'status' => ['id' => 5, 'name' => 'Closed'], 'isregression' => true];
        self::assertTrue(Issue::fromDocument($closed)->regression);
        self::assertSame([], Issue::fromDocument($closed)->marks());
    }
}
