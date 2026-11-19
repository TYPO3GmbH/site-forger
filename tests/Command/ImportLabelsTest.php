<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Command;

use App\Command\ImportCommand;
use PHPUnit\Framework\TestCase;

/**
 * The votes the import stores for a Gerrit change, from the labels Gerrit sends with DETAILED_LABELS.
 */
final class ImportLabelsTest extends TestCase
{
    public function testSumsAndTheLowestAndHighestSingleVote(): void
    {
        $labels = $this->extract([
            'Code-Review' => ['all' => [
                ['_account_id' => 1, 'value' => 2],
                ['_account_id' => 2, 'value' => 1],
                ['_account_id' => 3, 'value' => 0],
            ]],
            'Verified' => ['all' => [
                // The CI user is no reviewer
                ['_account_id' => 49454, 'value' => -2],
                ['_account_id' => 4, 'value' => -1],
                ['_account_id' => 5, 'value' => 1],
            ]],
        ]);

        self::assertSame([
            'positive_reviews' => 3,
            'negative_reviews' => 0,
            'positive_verifications' => 1,
            'negative_verifications' => 1,
            'lowest_review_vote' => 0,
            'highest_review_vote' => 2,
            'lowest_verification_vote' => -1,
            'highest_verification_vote' => 1,
        ], $labels);
    }

    public function testWithoutVotes(): void
    {
        $labels = $this->extract(['Code-Review' => [], 'Verified' => ['all' => [['_account_id' => 1]]]]);

        self::assertSame(0, $labels['lowest_review_vote']);
        self::assertSame(0, $labels['highest_verification_vote']);
    }

    /**
     * @param array<string, mixed> $labels
     *
     * @return array<string, int>
     */
    private function extract(array $labels): array
    {
        $command = (new \ReflectionClass(ImportCommand::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(ImportCommand::class, 'extractLabels'))->invoke($command, $labels);
    }
}
