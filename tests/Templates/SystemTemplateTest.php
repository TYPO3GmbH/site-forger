<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

use App\Enum\SystemCheckEnum;
use App\Import\ChangeSweepRound;
use App\Import\IssueComparison;
use App\People\UserStatistics;
use App\Setup\SetupSetting;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * The content of the system page with every section filled, as SystemController passes them.
 */
final class SystemTemplateTest extends KernelTestCase
{
    public function testThePendingImportsCountTheChecksThatAreNotOk(): void
    {
        $html = $this->render([
            self::pending('issue', SystemCheckEnum::OK, 0),
            self::pending('change', SystemCheckEnum::ATTENTION, 12),
        ]);

        self::assertStringContainsString('come from an import before version 3', $html);
        // Where the documents stand, by the version of the import
        $text = (string) preg_replace('/\s+/', ' ', strip_tags($html));
        self::assertStringContainsString('Version 3: 88, current', $text);
        self::assertStringContainsString('Version 2: 12, the backfill lifts them', $text);
        self::assertStringContainsString('Look at: Setup, Imports', $html);
        self::assertStringContainsString('<code class="system-list-name">FORGE_ACCESS_TOKEN</code>', $html);
    }

    public function testAnIndexTellsTheVersionsOfItsEvents(): void
    {
        $html = $this->render([self::pending('change', SystemCheckEnum::ATTENTION, 12)]);
        $html = (string) preg_replace('/\s+/', ' ', strip_tags($html));

        self::assertStringContainsString('Events of version 7: 60, current', $html);
        self::assertStringContainsString('Events of version 6: 40, the backfill writes their events again', $html);
    }

    public function testTheCardOfTheUsersLeadsToTheAccountsWithoutAUsername(): void
    {
        $html = $this->render([], [
            'users' => [
                'result' => SystemCheckEnum::ATTENTION,
                'summary' => 'Forger knows the TYPO3 login of 4,203 of 5,632 people.',
                'reasons' => '1,375 not asked for yet · 54 unknown to Forge (54 refused (403))',
                'emails' => 'An email address for 3,432 of 5,632 people · 771 with no email on Forge',
                'hint' => null,
            ],
            'withoutUsernameCount' => 825,
        ]);

        self::assertMatchesRegularExpression('/825\s+accounts of Forge and Gerrit\s+without a username/', $html);
        self::assertStringContainsString('href="/management/people?view=no-username"', $html);
    }

    public function testEachSectionHasATileThatLeadsToIt(): void
    {
        $html = $this->render([self::pending('change', SystemCheckEnum::ATTENTION, 12)], [
            'indices' => [
                self::index('issue_english', 'current', SystemCheckEnum::OK),
                self::index('user_english', 'current', SystemCheckEnum::OK),
            ],
            'userStatistics' => new UserStatistics(200, 50, 40, 120, 30, 20, 30, [404 => 30], null),
        ]);

        self::assertMatchesRegularExpression('/Overall.*2 of 5.*Look at: Setup, Imports/s', $html);
        self::assertStringContainsString('href="#system-setup"', $html);
        self::assertStringContainsString('id="system-setup"', $html);
        // The documents of all indices, and the outdated ones
        self::assertStringContainsString('<span class="system-tile-value">78,786</span>', $html);
        self::assertStringContainsString('<span class="system-tile-value">12 outdated</span>', $html);
        self::assertStringContainsString('<span class="system-tile-value">25 %</span>', $html);
        self::assertStringContainsString('style="width: 25%"', $html);
    }

    public function testTheComparisonWithGerritTellsWhereItStands(): void
    {
        $round = new ChangeSweepRound(
            new \DateTimeImmutable('2020-01-22 11:47:50'),
            new \DateTimeImmutable('2026-11-24 19:00:00'),
            499,
            2,
        );

        $html = $this->render([], [
            'sweep' => ['round' => $round, 'share' => 0.8, 'result' => SystemCheckEnum::ATTENTION],
        ]);

        self::assertStringContainsString('<span class="system-figure-value">80 %</span>', $html);
        self::assertMatchesRegularExpression('#<dt>Missing</dt>\s*<dd class="runs-numeric">499</dd>#', $html);
        self::assertStringContainsString('No round has finished yet', $html);
        self::assertStringContainsString('Gerrit round at 80 %', $html);
        self::assertStringContainsString('What does this mean?', $html);
    }

    public function testTheComparisonWithForgeTellsHowFullTheIndexIs(): void
    {
        $forge = IssueComparison::of(null, new \DateTimeImmutable('2026-11-24 19:28'), 60000, 45000, 2, 250, 1);

        $html = $this->render([], ['forge' => $forge]);

        self::assertMatchesRegularExpression(
            '#<span class="system-figure-value">75 %</span>\s*'
            . '<span class="system-figure-label">of the issues match Forge#',
            $html,
        );
        self::assertMatchesRegularExpression('#<dt>Differ</dt>\s*<dd class="runs-numeric">15,000</dd>#', $html);
        self::assertStringContainsString('style="width: 75%"', $html);
        self::assertStringContainsString('<dt>Ranges compared</dt>', $html);
        self::assertMatchesRegularExpression('#<dt>Missing, last run</dt>\s*<dd class="runs-numeric">250</dd>#', $html);
    }

    public function testOneIssueOffIsNotAHundredPercent(): void
    {
        $forge = IssueComparison::of(null, new \DateTimeImmutable('2026-11-24 19:28'), 61242, 61241);

        $html = $this->render([], ['forge' => $forge]);

        self::assertStringContainsString('<span class="system-figure-value">99 %</span>', $html);
    }

    public function testTheClusterNamesItsHealthNodesAndShardsInALine(): void
    {
        $html = $this->render([]);

        self::assertStringContainsString('Version 8.19.0', $html);
        self::assertMatchesRegularExpression('/Green\s+· 1 node\s+· 0 unassigned\s+shards/u', $html);
        // Only a single yellow node needs the explanation
        self::assertStringNotContainsString('A single node is always', $html);
    }

    public function testAProblemOfTheSetupLinksToWhereToFixIt(): void
    {
        $setting = new SetupSetting(
            'FORGE_ACCESS_TOKEN',
            'Users from Forge',
            SystemCheckEnum::PROBLEM,
            'Logged in as typo3com_bamboo.',
            'The account is no member of the Forge project TYPO3 Core (typo3cms-core).',
            ['label' => 'Members in Forge', 'url' => 'https://forge.typo3.org/projects/typo3cms-core/settings/members'],
        );
        $html = $this->render([], ['setup' => [$setting]]);

        $membersUrl = 'https://forge.typo3.org/projects/typo3cms-core/settings/members';
        self::assertStringContainsString('<span class="system-list-detail">Logged in as typo3com_bamboo.', $html);
        self::assertStringContainsString('<span class="system-list-problem">The account is no member', $html);
        self::assertStringContainsString('href="' . $membersUrl . '"', $html);
    }

    public function testAnIndexExplainsItsConfigurationOnlyIfItIsNotCurrent(): void
    {
        $html = $this->render([], ['indices' => [
            self::index('issue_english', 'current', SystemCheckEnum::OK),
            self::index('user_english', 'outdated', SystemCheckEnum::ATTENTION),
        ]]);

        self::assertStringContainsString('<code class="system-list-name">issue_english</code>', $html);
        self::assertStringContainsString('39,393 documents', $html);
        self::assertSame(1, substr_count($html, 'Run app:elasticsearch:setup to rebuild the index.'));
        self::assertStringNotContainsString('Matches the configuration', $html);
    }

    /**
     * @param list<array<string, mixed>> $pending
     * @param array<string, mixed>       $variables the variables to replace, like the users, see SystemController
     */
    private function render(array $pending, array $variables = []): string
    {
        self::bootKernel();
        $container = self::getContainer();
        $container->get('request_stack')->push(Request::create('/management/system'));
        /** @var Environment $twig */
        $twig = $container->get('twig');

        // The content only: the layout around reads the menu from the database
        return $twig->load('management/system/index.html.twig')->renderBlock('body', [
            'elasticsearch' => [
                'reachable' => true,
                'error' => null,
                'version' => '8.19.0',
                'versionResult' => SystemCheckEnum::OK,
                'cluster' => 'forger',
                'health' => 'green',
                'healthResult' => SystemCheckEnum::OK,
                'nodes' => 1,
                'unassignedShards' => 0,
            ],
            'pending' => $pending,
            ...$variables + [
                'sweep' => null,
                'forge' => null,
                'setup' => [
                    new SetupSetting('FORGE_ACCESS_TOKEN', 'Users from Forge', SystemCheckEnum::PROBLEM, 'Not set.'),
                ],
                'indices' => [],
                'users' => null,
                'userStatistics' => null,
                'withoutUsernameCount' => 0,
            ],
            'application' => ['php' => PHP_VERSION, 'symfony' => '7.4', 'environment' => 'test', 'debug' => false],
            'checks' => [
                'Setup' => SystemCheckEnum::PROBLEM,
                'Elasticsearch' => SystemCheckEnum::OK,
                'Indices' => SystemCheckEnum::OK,
                'Imports' => SystemCheckEnum::ATTENTION,
                'Users' => SystemCheckEnum::OK,
            ],
            'overall' => SystemCheckEnum::ATTENTION,
            'notOk' => ['Setup', 'Imports'],
            'minimumVersion' => '8.0',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function index(string $alias, string $configuration, SystemCheckEnum $result): array
    {
        return [
            'alias' => $alias,
            'index' => $alias . '_b',
            'configuration' => $configuration,
            'result' => $result,
            'documents' => 39393,
            'bytes' => 53_000_000,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pending(string $index, SystemCheckEnum $result, int $matches): array
    {
        return [
            'label' => 'Version 3 of the ' . $index . ' index',
            'index' => $index,
            'version' => 3,
            'description' => 'Loaded again by the next delta.',
            'matches' => $matches,
            'documents' => 100,
            'versions' => [
                ['version' => 3, 'documents' => 100 - $matches, 'state' => 'current'],
                ['version' => 2, 'documents' => $matches, 'state' => 'backfill'],
            ],
            'streamVersions' => [
                ['version' => 7, 'documents' => 60, 'state' => 'current'],
                ['version' => 6, 'documents' => 40, 'state' => 'backfill'],
            ],
            'result' => $result,
        ];
    }
}
