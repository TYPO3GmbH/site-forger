<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Document;

use App\Document\ReviewSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewSubjectTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function subjects(): iterable
    {
        yield 'one tag' => ['[BUGFIX] Fix the thing', ['BUGFIX']];
        yield 'breaking feature' => ['[!!!][FEATURE] Remove the old API', ['!!!', 'FEATURE']];
        yield 'work in progress' => ['[WIP][TASK] Try something', ['WIP', 'TASK']];
        yield 'no tag' => ['Fix the thing', []];
        yield 'empty brackets' => ['[] Fix the thing', []];
        yield 'other punctuation' => ['[!!] [?] Fix the thing', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('subjects')]
    public function testTags(string $subject, array $expected): void
    {
        self::assertSame($expected, ReviewSubject::tags($subject));
    }

    public function testWorkInProgress(): void
    {
        self::assertTrue(ReviewSubject::isWorkInProgress(['WIP', 'TASK']));
        self::assertTrue(ReviewSubject::isWorkInProgress(['POC']));
        self::assertFalse(ReviewSubject::isWorkInProgress(['!!!', 'FEATURE']));
        self::assertFalse(ReviewSubject::isWorkInProgress([]));
    }
}
