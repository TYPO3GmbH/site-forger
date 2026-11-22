<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Api;

use App\Api\ChangeMapper;
use App\Api\IssueMapper;
use App\Api\Model\ActivityCalendar;
use App\Api\Model\ActivityClosedYear;
use App\Api\Model\ActivityClosedYears;
use App\Api\Model\ActivityCount;
use App\Api\Model\ActivityCrew;
use App\Api\Model\ActivityDuration;
use App\Api\Model\ActivityEvent;
use App\Api\Model\ActivityFigure;
use App\Api\Model\ActivityNewcomer;
use App\Api\Model\ActivityNewcomers;
use App\Api\Model\ActivityPage;
use App\Api\Model\ActivityPerson;
use App\Api\Model\ActivitySeries;
use App\Api\Model\ActivityStatistics;
use App\Api\Model\ActivityTrend;
use App\Api\Model\ActivityWaitBand;
use App\Api\Model\ActivityWeekHours;
use App\Api\Model\ActivityWindow;
use App\Api\Model\Bucket;
use App\Api\Model\IssueBreakdown;
use App\Api\Model\IssuePage;
use App\Duplicates\DuplicateGroups;
use App\Duplicates\DuplicateHit;
use Doctrine\Common\Collections\ArrayCollection;
use Nelmio\ApiDocBundle\ApiDocGenerator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The documentation at /api/doc describes every endpoint and every field the API returns.
 */
final class ApiDocumentationTest extends KernelTestCase
{
    public function testDescribesEveryEndpoint(): void
    {
        $paths = array_keys(self::document()['paths']);
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with($name, 'api_v1_')) {
                self::assertContains($route->getPath(), $paths, $name . ' is not documented');
            }
        }
    }

    public function testDescribesEveryFieldOfAnIssue(): void
    {
        $issue = new IssueMapper(ApiFixtures::urls())->detail(
            ApiFixtures::issue(),
            ApiFixtures::changesOfTheIssue(),
            ApiFixtures::subtasks(),
            ApiFixtures::indexedRelatedIssues(),
        );

        self::assertFieldsDescribed('IssueDetail', $issue);
    }

    public function testDescribesEveryFieldOfAChange(): void
    {
        $change = new ChangeMapper(ApiFixtures::urls())->detail(
            ApiFixtures::change(),
            ApiFixtures::issuesOfTheChange(),
            new ArrayCollection([97865 => ApiFixtures::change()]),
        );

        self::assertFieldsDescribed('ChangeDetail', $change);
    }

    public function testDescribesEveryFieldOfTheDuplicates(): void
    {
        $hit = DuplicateHit::checked(ApiFixtures::issue(), 70, 60);
        $list = new IssueMapper(ApiFixtures::urls())->duplicates(ApiFixtures::issue(), new DuplicateGroups([], [$hit]));

        self::assertFieldsDescribed('DuplicateList', $list);
        self::assertFieldsDescribed('Duplicate', $list->items[0]);
    }

    public function testDescribesEveryFieldOfTheBreakdown(): void
    {
        $bucket = new Bucket('New', 3);
        $breakdown = new IssueBreakdown([$bucket], [$bucket], [$bucket], [$bucket], [$bucket]);
        $summary = new IssueMapper(ApiFixtures::urls())->summary(ApiFixtures::issue());
        $page = new IssuePage(1, 0, 25, [$summary], $breakdown);

        self::assertFieldsDescribed('IssuePage', $page);
        self::assertFieldsDescribed('IssueBreakdown', $breakdown);
    }

    public function testDescribesEveryFieldOfTheActivity(): void
    {
        $person = new ActivityPerson(
            'Jane Doe',
            36415,
            14410,
            'forge:36415',
            'https://www.gravatar.com/avatar/x',
            'jdoe',
        );
        $window = new ActivityWindow(
            '2026-11-14T23:00:00Z',
            '2026-11-21T21:00:00Z',
            true,
            7,
            'Europe/Berlin',
            'day',
            ['hour', 'day'],
        );
        $event = new ActivityEvent(
            'gerrit-91487-a1',
            'gerrit',
            'voted',
            91487,
            '[BUGFIX] Clear the cache',
            '2026-11-21T18:37:29Z',
            $person,
            false,
            ['Code-Review +2'],
            3,
            'main',
            'https://review.typo3.org/c/Packages/TYPO3.CMS/+/91487/',
            'https://forger.test/change/91487',
        );
        $count = new ActivityCount('main', 'main', 816);
        $page = new ActivityPage(1, 0, 25, $window, $person, [$count], [$event]);
        $trend = new ActivityTrend(133, 112, [4, 5], 'more');
        $figure = new ActivityFigure(
            'reviews',
            'Reviews',
            'Votes and comments',
            42,
            37,
            [20, 22],
            'gerrit',
            ['voted'],
            'more',
            0,
        );
        $duration = new ActivityDuration(
            'time-to-close',
            'Time to close',
            'From the first patch set until the change was merged or abandoned',
            704,
            432000,
            518400,
            [new ActivityWaitBand('week', '1 to 7 days', 30, [new ActivityCount('merged', 'Merged', 24)])],
            'gerrit',
            'less',
        );
        $newcomer = new ActivityNewcomer(
            $person,
            'first-merge',
            'gerrit',
            97873,
            '[BUGFIX] Clear the cache',
            '2026-11-20T08:55:32Z',
            'main',
            'merged',
            'Merged',
            false,
            'https://review.typo3.org/c/Packages/TYPO3.CMS/+/97873/',
            'https://forger.test/change/97873',
            $person,
        );
        $newcomers = new ActivityNewcomers($window, 8, 6, 'more', [$count], [$newcomer]);
        $crew = new ActivityCrew(
            'merging',
            'Merges',
            'Who merged changes',
            16,
            3,
            2,
            [24.1, 15.3, 11.2],
            'more',
        );
        $calendar = new ActivityCalendar($window, '2026-11-15', [700, 739]);
        $weekHours = new ActivityWeekHours($window, [array_fill(0, 24, 0)]);
        $closedYear = new ActivityClosedYear(2026, [310, 598, null]);
        $closedYears = new ActivityClosedYears([$closedYear], [$closedYear]);
        $statistics = new ActivityStatistics(
            $window,
            $person,
            1439,
            $trend,
            $trend,
            [$figure],
            $trend,
            $trend,
            [$duration],
            [$count],
            [$crew],
            [$count],
            [$count],
            ['2026-11-15', '2026-11-16'],
            [new ActivitySeries('total', 'Events', [700, 739])],
        );

        self::assertFieldsDescribed('ActivityPage', $page);
        self::assertFieldsDescribed('ActivityEvent', $event);
        self::assertFieldsDescribed('ActivityPerson', $person);
        self::assertFieldsDescribed('ActivityStatistics', $statistics);
        self::assertFieldsDescribed('ActivityFigure', $figure);
        self::assertFieldsDescribed('ActivityTrend', $trend);
        self::assertFieldsDescribed('ActivityDuration', $duration);
        self::assertFieldsDescribed('ActivityCrew', $crew);
        self::assertFieldsDescribed('ActivityNewcomer', $newcomer);
        self::assertFieldsDescribed('ActivityNewcomers', $newcomers);
        self::assertFieldsDescribed('ActivityCalendar', $calendar);
        self::assertFieldsDescribed('ActivityWeekHours', $weekHours);
        self::assertFieldsDescribed('ActivityClosedYear', $closedYear);
        self::assertFieldsDescribed('ActivityClosedYears', $closedYears);
    }

    public function testEveryFieldHasADescription(): void
    {
        foreach (self::document()['components']['schemas'] as $schema => $definition) {
            foreach ($definition['properties'] ?? [] as $field => $property) {
                self::assertNotEmpty($property['description'] ?? '', $schema . '.' . $field . ' has no description');
            }
        }
    }

    public function testEveryFieldIsRequired(): void
    {
        // Every object has all of its fields, a field without a value is null
        foreach (self::document()['components']['schemas'] as $schema => $definition) {
            self::assertSame(
                array_keys($definition['properties'] ?? []),
                $definition['required'] ?? [],
                $schema . ' does not require all of its fields',
            );
        }
    }

    public function testEveryEnumIsAListOfValues(): void
    {
        $document = self::document();
        array_walk_recursive($document, static function (mixed $value, int|string $key): void {
            if ('enum' === $key) {
                self::fail('An enum is a list of values, not ' . var_export($value, true));
            }
        });
        $state = $document['components']['schemas']['IssueChange']['properties']['state'];
        self::assertSame(['ready', 'wip', 'merged', 'abandoned'], $state['enum']);
    }

    public function testEveryEndpointHasItsOwnOperationId(): void
    {
        $operationIds = [];
        foreach (self::document()['paths'] as $operations) {
            foreach ($operations as $operation) {
                self::assertMatchesRegularExpression('/^[a-z][A-Za-z]+$/', $operation['operationId'] ?? '');
                $operationIds[] = $operation['operationId'];
            }
        }

        self::assertSame($operationIds, array_unique($operationIds));
    }

    public function testEveryEndpointDescribesTheLimit(): void
    {
        foreach (self::document()['paths'] as $path => $operations) {
            foreach ($operations as $operation) {
                self::assertArrayHasKey('429', $operation['responses'], $path . ' does not describe the limit');
            }
        }
    }

    public function testNeedsNoLogin(): void
    {
        self::assertSame([], self::document()['security']);
    }

    /**
     * The fields of the object as JSON, those of its objects and lists of objects, too, in the schema of the document.
     */
    private static function assertFieldsDescribed(string $schema, object $model): void
    {
        $data = (array) json_decode((string) json_encode($model), true);
        $schemas = self::document()['components']['schemas'];
        $properties = $schemas[$schema]['properties'];

        self::assertSame([], array_values(array_diff(array_keys($properties), array_keys($data))), $schema);
        foreach ($data as $field => $value) {
            self::assertArrayHasKey($field, $properties, $schema . '.' . $field . ' is not documented');
            $reference = self::reference($properties[$field]);
            if (null !== $reference && is_array($value)) {
                $item = array_is_list($value) ? ($value[0] ?? []) : $value;
                self::assertSame(
                    array_keys($schemas[$reference]['properties']),
                    array_keys($item),
                    $schema . '.' . $field,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $property
     */
    private static function reference(array $property): ?string
    {
        $ref = $property['$ref'] ?? $property['items']['$ref'] ?? $property['oneOf'][0]['$ref']
            ?? $property['allOf'][0]['$ref'] ?? null;

        return null === $ref ? null : basename((string) $ref);
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        self::bootKernel();
        /** @var ApiDocGenerator $generator */
        $generator = self::getContainer()->get('nelmio_api_doc.generator.default');

        return (array) json_decode($generator->generate()->toJson(), true);
    }
}
