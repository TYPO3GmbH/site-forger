<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Enum;

use App\DTO\TrackerFilters;
use App\Enum\PriorityEnum;
use App\Enum\TrackerEnum;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class IssueAttributeEnumTest extends TestCase
{
    public function testTrackersAreTheForgeIdsWithLabels(): void
    {
        self::assertSame(TrackerEnum::EPIC, TrackerEnum::from(10));
        self::assertSame('Work Package', TrackerEnum::WORK_PACKAGE->label());
        self::assertSame(TrackerEnum::MAJOR_FEATURE, TrackerEnum::tryFromLabel('Major Feature'));
        self::assertNotContains(8, array_column(TrackerEnum::cases(), 'value'));
    }

    public function testTrackersWithoutOwnColorUseTheDefault(): void
    {
        self::assertSame('bug', TrackerEnum::BUG->severity());
        self::assertSame('feature', TrackerEnum::MAJOR_FEATURE->severity());
        self::assertSame('default', TrackerEnum::SUPPORT->severity());
    }

    public function testPrioritiesAreTheForgeIdsWithLabels(): void
    {
        self::assertSame(PriorityEnum::MUST_HAVE, PriorityEnum::from(3));
        self::assertSame(PriorityEnum::WONT_HAVE, PriorityEnum::tryFromLabel("Won't have this time"));
        self::assertSame('must', PriorityEnum::MUST_HAVE->severity());
        self::assertTrue(PriorityEnum::SHOULD_HAVE->isDefault());
        self::assertFalse(PriorityEnum::UNDEFINED->isDefault());
    }

    public function testLabelsAndSeveritiesAreUnique(): void
    {
        $trackerLabels = array_map(static fn (TrackerEnum $tracker): string => $tracker->label(), TrackerEnum::cases());
        $priorityLabels = array_map(static fn (PriorityEnum $priority): string => $priority->label(), PriorityEnum::cases());
        $prioritySeverities = array_map(static fn (PriorityEnum $priority): string => $priority->severity(), PriorityEnum::cases());

        self::assertSame($trackerLabels, array_values(array_unique($trackerLabels)));
        self::assertSame($priorityLabels, array_values(array_unique($priorityLabels)));
        self::assertSame($prioritySeverities, array_values(array_unique($prioritySeverities)));
    }

    public function testTrackerFiltersReadTheExcludedTrackersBySeverity(): void
    {
        $filters = new TrackerFilters(new Request(['trackerFilters' => ['bug' => 'true', 'epic' => 'true', 'support' => 'true']]));

        self::assertSame([TrackerEnum::EPIC, TrackerEnum::BUG], $filters->excluded);
        self::assertSame([TrackerEnum::STORY, TrackerEnum::FEATURE, TrackerEnum::TASK], $filters->included());
        self::assertTrue($filters->isExcluded(TrackerEnum::BUG));
    }
}
