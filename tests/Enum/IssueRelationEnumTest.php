<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Enum;

use App\Document\Issue;
use App\Document\IssueRelation;
use App\Enum\IssueRelationEnum;
use PHPUnit\Framework\TestCase;

final class IssueRelationEnumTest extends TestCase
{
    public function testTheReverseOfTheReverseIsTheRelationItself(): void
    {
        foreach (IssueRelationEnum::cases() as $type) {
            self::assertSame($type, $type->reverse()->reverse());
        }
        self::assertSame(IssueRelationEnum::BLOCKED_BY, IssueRelationEnum::BLOCKS->reverse());
        self::assertSame(IssueRelationEnum::RELATES, IssueRelationEnum::RELATES->reverse());
    }

    public function testTheIssueReadsItsParentAndRelations(): void
    {
        $issue = Issue::fromDocument([
            'id' => 91089,
            'parent' => ['id' => 90000],
            'issue_relations' => [
                ['type' => 'blocks', 'issue' => 80003],
                ['type' => 'unknown', 'issue' => 80004],
                ['type' => 'relates', 'issue' => 0],
            ],
        ]);

        self::assertSame(90000, $issue->parent);
        self::assertEquals([new IssueRelation(IssueRelationEnum::BLOCKS, 80003)], $issue->relations);
        self::assertNull(Issue::fromDocument(['id' => 1])->parent);
    }
}
