<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Mock;

use App\Enum\IssueStatusEnum;
use App\Mock\MockDataGenerator;
use PHPUnit\Framework\TestCase;

final class MockDataGeneratorTest extends TestCase
{
    private const array PEOPLE = [
        ['name' => 'Dev User', 'email' => 'dev-user@forger.ddev.site', 'account' => 'dev-user'],
        ['name' => 'Dev Admin', 'email' => 'dev-admin@forger.ddev.site', 'account' => 'dev-admin'],
        ['name' => 'Max Mock', 'email' => '', 'account' => 'maxmock'],
    ];

    private MockDataGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new MockDataGenerator(self::PEOPLE, new \DateTimeImmutable('2026-11-19 12:00:00'));
    }

    public function testEveryPersonGetsAnIssueInEveryState(): void
    {
        $combinations = [];
        foreach ($this->generator->issues() as $issue) {
            $combinations[$issue['author']['name']][] = $issue['status']['name'];
        }

        $states = array_map(static fn (IssueStatusEnum $state): string => $state->label(), IssueStatusEnum::cases());
        foreach (self::PEOPLE as $person) {
            self::assertEqualsCanonicalizing($states, $combinations[$person['name']]);
        }
    }

    public function testEveryPersonGetsAChangeInEveryState(): void
    {
        $combinations = [];
        foreach ($this->generator->reviews() as $review) {
            $combinations[$review['owner']][] = $review['reviewstatus'] . ($review['work_in_progress'] ? '+WIP' : '');
        }

        foreach (self::PEOPLE as $person) {
            foreach (['NEW', 'NEW+WIP', 'MERGED', 'ABANDONED', 'ABANDONED+WIP'] as $state) {
                self::assertContains($state, $combinations[$person['name']]);
            }
        }
    }

    public function testEveryPersonIsAuthorAndAssigneeInEveryCombination(): void
    {
        $roles = [];
        foreach ($this->generator->issues() as $issue) {
            $author = $issue['author']['name'];
            $assignee = $issue['assigned_to']['name'] ?? null;
            $roles[$author][null === $assignee ? 'unassigned' : ($assignee === $author ? 'self' : 'other')] = true;
            if (null !== $assignee && $assignee !== $author) {
                $roles[$assignee]['assigned only'] = true;
            }
        }

        foreach (self::PEOPLE as $person) {
            self::assertEqualsCanonicalizing(['unassigned', 'self', 'other', 'assigned only'], array_keys($roles[$person['name']]));
        }
    }

    public function testDocumentsStayInTheMockIdRange(): void
    {
        foreach ([...$this->generator->issues(), ...$this->generator->reviews()] as $document) {
            self::assertGreaterThan(MockDataGenerator::ID_OFFSET, $document['id']);
            self::assertLessThanOrEqual(MockDataGenerator::ID_LIMIT, $document['id']);
        }
        foreach ($this->generator->interactions() as $interaction) {
            self::assertStringStartsWith(MockDataGenerator::INTERACTION_PREFIX, $interaction['interaction_id']);
        }
    }

    public function testIssuesUnderReviewLinkAnExistingChange(): void
    {
        $reviewIds = array_column($this->generator->reviews(), 'id');
        $statusById = array_column($this->generator->reviews(), 'reviewstatus', 'id');
        $linkedStates = [];
        foreach ($this->generator->issues() as $issue) {
            if ('Under Review' !== $issue['status']['name']) {
                self::assertArrayNotHasKey('journals', $issue);
                continue;
            }
            self::assertMatchesRegularExpression('|review\.typo3\.org/c/Packages/TYPO3\.CMS/\+/(\d+)|', $issue['journals'][0]['notes']);
            preg_match('|/\+/(\d+)|', $issue['journals'][0]['notes'], $matches);
            self::assertContains((int) $matches[1], $reviewIds);
            $linkedStates[] = $statusById[(int) $matches[1]];
        }
        // Open changes keep an issue from being stale, closed ones do not: both have to exist
        self::assertContains('NEW', $linkedStates);
        self::assertContains('ABANDONED', $linkedStates);
    }

    public function testDatesUseTheIndexFormat(): void
    {
        $issue = $this->generator->issues()[0];
        $review = $this->generator->reviews()[0];

        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}$#', $issue['updated_on']);
        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}$#', $review['created_on']);
    }

    public function testTheSameInputGivesTheSameDocuments(): void
    {
        $again = new MockDataGenerator(self::PEOPLE, new \DateTimeImmutable('2026-11-19 12:00:00'));

        self::assertSame($this->generator->issues(), $again->issues());
        self::assertSame($this->generator->interactions(), $again->interactions());
    }
}
