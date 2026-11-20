<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Service;

use App\Service\ForgeIssueList;
use PHPUnit\Framework\TestCase;

final class ForgeIssueListTest extends TestCase
{
    private const string FILTERS = 'set_filter=1&f[]=status_id&op[status_id]=%2A&f[]=subproject_id&op[subproject_id]=%21%2A&f[]=is_private&op[is_private]=%3D&v[is_private][]=0';

    public function testCountsTheIssuesOfTheIndexOnly(): void
    {
        // Not of the subprojects, not private: the issues the index holds
        self::assertSame(
            '/issues.json?project_id=27&' . self::FILTERS . '&sort=id%3Adesc&limit=1&include=relations',
            ForgeIssueList::path(27, null, null, null, 1, 'id:desc')
        );
    }

    public function testListsARangeOfNumbers(): void
    {
        self::assertSame(
            '/issues.json?project_id=27&' . self::FILTERS . '&f[]=issue_id&op[issue_id]=%3E%3C&v[issue_id][]=100000&v[issue_id][]=100099&sort=id&limit=100&include=relations',
            ForgeIssueList::path(27, 100000, 100099, null, 100, 'id')
        );
    }

    public function testListsNumbers(): void
    {
        self::assertSame(
            '/issues.json?project_id=27&' . self::FILTERS . '&f[]=issue_id&op[issue_id]=%3D&v[issue_id][]=111513,111464&sort=id&limit=100&include=relations',
            ForgeIssueList::path(27, null, null, [111513, 111464], 100, 'id')
        );
    }
}
