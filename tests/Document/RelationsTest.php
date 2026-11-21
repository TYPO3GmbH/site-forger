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
use App\Document\IssueRelation;
use App\Document\Relations;
use App\Enum\IssueRelationEnum;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class RelationsTest extends TestCase
{
    public function testLeavesOutTheRelatedIssuesTheIndexDoesNotHave(): void
    {
        // The token of the import sees private issues in the relations, the index has none of them
        $related = Relations::related(
            [
                new IssueRelation(IssueRelationEnum::RELATES, 110316),
                new IssueRelation(IssueRelationEnum::BLOCKS, 104990),
            ],
            new ArrayCollection([104990 => Issue::fromDocument(['id' => 104990, 'subject' => 'Public'])]),
        );

        self::assertCount(1, $related);
        self::assertSame(104990, $related[0]->id);
        self::assertSame('Public', $related[0]->issue?->subject);
    }

    public function testOrdersTheRelatedIssuesByTheirType(): void
    {
        $found = new ArrayCollection([
            1 => Issue::fromDocument(['id' => 1, 'subject' => 'One']),
            2 => Issue::fromDocument(['id' => 2, 'subject' => 'Two']),
        ]);

        $related = Relations::related(
            [new IssueRelation(IssueRelationEnum::FOLLOWS, 1), new IssueRelation(IssueRelationEnum::RELATES, 2)],
            $found,
        );

        self::assertSame([2, 1], array_map(static fn ($item): int => $item->id, $related));
    }
}
