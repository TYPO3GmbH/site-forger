<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\ProjectIssues;
use PHPUnit\Framework\TestCase;

final class ProjectIssuesTest extends TestCase
{
    private ProjectIssues $projectIssues;

    protected function setUp(): void
    {
        $this->projectIssues = new ProjectIssues(27);
    }

    public function testOnlyTheIssuesOfTheProjectItselfAreImported(): void
    {
        self::assertTrue($this->projectIssues->belongsToProject(['id' => 1, 'project' => ['id' => 27], 'is_private' => false]));
        // Forge lists the issues of the subprojects with those of the project
        self::assertFalse($this->projectIssues->belongsToProject(['id' => 2, 'project' => ['id' => 42]]));
        self::assertFalse($this->projectIssues->belongsToProject(['id' => 3, 'project' => ['id' => 27], 'is_private' => true]));
        self::assertFalse($this->projectIssues->belongsToProject(['id' => 4]));
    }

    public function testNotesAreFiltered(): void
    {
        $issue = ['id' => 1, 'journals' => [
            ['id' => 10, 'private_notes' => false],
            ['id' => 11, 'private_notes' => true],
            ['id' => 12],
        ]];

        self::assertSame([10, 12], array_column($this->projectIssues->withoutPrivateNotes($issue)['journals'], 'id'));
        self::assertSame(['id' => 1], $this->projectIssues->withoutPrivateNotes(['id' => 1]));
    }
}
