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

    public function testAGoodFirstIssueIsEasyUnstartedAndUnassigned(): void
    {
        $easy = ['id' => 1, 'status' => ['id' => 2, 'name' => 'Accepted'], 'complexity' => 'trivial'];
        $marks = static fn (array $document): array => Issue::fromDocument($document)->marks();

        self::assertSame([IssueMarkEnum::GOOD_FIRST_ISSUE], $marks($easy));
        self::assertSame(
            [IssueMarkEnum::REGRESSION, IssueMarkEnum::GOOD_FIRST_ISSUE],
            $marks($easy + ['isregression' => true]),
        );
        self::assertSame([], $marks(['complexity' => 'medium'] + $easy));
        self::assertSame([], $marks(['status' => ['id' => 8, 'name' => 'Under Review']] + $easy));
        self::assertSame([], $marks($easy + ['assigned_to' => ['id' => 7, 'name' => 'Benni Mack']]));
        self::assertSame([], $marks(['id' => 1, 'status' => ['id' => 1, 'name' => 'New']]));
    }
}
