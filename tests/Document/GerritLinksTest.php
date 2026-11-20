<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Document;

use App\Document\GerritLinks;
use PHPUnit\Framework\TestCase;

final class GerritLinksTest extends TestCase
{
    public function testFindsTheChangesOfAllNotesOnce(): void
    {
        $issue = ['journals' => [
            ['notes' => "Patch set 1 for branch *main* has been pushed to the review server.\nIt is available at https://review.typo3.org/c/Packages/TYPO3.CMS/+/81234"],
            ['notes' => 'See https://review.typo3.org/81240 and https://review.typo3.org/c/Packages/TYPO3.CMS/+/81234/2'],
            ['details' => []],
            ['notes' => 'No link here'],
        ]];

        self::assertSame([81234, 81240], GerritLinks::changeNumbers($issue));
    }

    public function testIssuesWithoutJournalsLinkNoChange(): void
    {
        self::assertSame([], GerritLinks::changeNumbers(['id' => 91089]));
    }
}
