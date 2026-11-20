<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Enum;

use App\Enum\ReviewLabelEnum;
use PHPUnit\Framework\TestCase;

final class ReviewLabelEnumTest extends TestCase
{
    public function testTheLabelsAreTheGerritNames(): void
    {
        self::assertSame(['Code-Review', 'Verified'], array_column(ReviewLabelEnum::cases(), 'value'));
        self::assertSame('Code review', ReviewLabelEnum::CODE_REVIEW->label());
        self::assertSame('V', ReviewLabelEnum::VERIFIED->abbreviation());
    }

    public function testEachLabelHasTheFieldsOfItsVotes(): void
    {
        self::assertSame(
            ['positive_reviews', 'negative_reviews', 'lowest_review_vote', 'highest_review_vote'],
            ReviewLabelEnum::CODE_REVIEW->fields(),
        );
        self::assertSame('lowest_verification_vote', ReviewLabelEnum::VERIFIED->lowestField());
    }
}
