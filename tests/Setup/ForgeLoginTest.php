<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Setup;

use App\CommandRun\CommandRunRecorder;
use App\Import\ForgeIssueApi;
use App\Import\ForgeIssueList;
use App\People\User;
use App\Repository\UserRepository;
use App\Setup\ForgeLogin;
use App\Tests\Http\MockApi;
use Doctrine\DBAL\Connection;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ForgeLoginTest extends TestCase
{
    public function testAMemberOfTheProjectIsFine(): void
    {
        $api = $this->api([self::user(false, [27, 1716]), self::issues(61205)]);

        self::assertSame(
            ['account' => 'benjaminkott (Benjamin Kott)', 'error' => null],
            $this->login($api)->check(),
        );
        self::assertStringContainsString('include=memberships', $api->request(0)->getUri()->getQuery());
    }

    public function testAnAdminNeedsNoMembership(): void
    {
        $api = $this->api([self::user(true, []), self::issues(61205)]);

        self::assertArrayNotHasKey('project', $this->login($api)->check());
    }

    public function testAnAccountOutsideTheProjectNamesTheProject(): void
    {
        $project = ['project' => ['id' => 27, 'name' => 'TYPO3 Core', 'identifier' => 'typo3cms-core']];
        $api = $this->api([
            self::user(false, [1716]),
            self::issues(61205),
            new Response(200, [], json_encode($project, JSON_THROW_ON_ERROR)),
        ]);

        $login = $this->login($api)->check();

        self::assertSame('TYPO3 Core (typo3cms-core)', $login['project'] ?? null);
        self::assertSame(
            'https://forge.typo3.org/projects/typo3cms-core/settings/members',
            $login['membersUrl'] ?? null,
        );
        self::assertSame('/projects/27.json', $api->request(2)->getUri()->getPath());
    }

    public function testTheProjectNumberStandsInForAProjectForgeHides(): void
    {
        $api = $this->api([self::user(false, []), self::issues(61205), new Response(403)]);

        $login = $this->login($api)->check();

        self::assertSame('#27', $login['project'] ?? null);
        self::assertSame('https://forge.typo3.org/projects/27/settings/members', $login['membersUrl'] ?? null);
    }

    public function testARoleThatMayNotViewTheIssuesIsAProblem(): void
    {
        $api = $this->api([self::user(false, [27]), new Response(403)]);

        $login = $this->login($api)->check();

        self::assertStringStartsWith('Forge refuses the issues of the project (403).', $login['issues'] ?? '');
        self::assertStringContainsString('"View issues"', $login['issues'] ?? '');
        self::assertSame('/issues.json', $api->request(1)->getUri()->getPath());
        self::assertStringContainsString('project_id=27', $api->request(1)->getUri()->getQuery());
    }

    public function testNoIssuesInTheListIsAProblem(): void
    {
        $api = $this->api([self::user(false, [27]), self::issues(0)]);

        self::assertStringStartsWith('Forge lists no issues', $this->login($api)->check()['issues'] ?? '');
    }

    public function testTheUserForgeAnsweredLastIsAskedAgain(): void
    {
        $api = $this->api([
            self::user(false, [27]),
            self::issues(61205),
            new Response(200, [], '{"user":{"id":37503}}'),
        ]);

        self::assertArrayNotHasKey('users', $this->login($api, self::lastFetched())->check());
        self::assertSame('/users/37503.json', $api->request(2)->getUri()->getPath());
    }

    public function testAHiddenUserIsAProblem(): void
    {
        $api = $this->api([self::user(false, [27]), self::issues(61205), new Response(404)]);

        self::assertStringStartsWith(
            'Forge hides the user #37503 (Jane Doe) from the account (404), but it showed the user on 2026-11-20.',
            $this->login($api, self::lastFetched())->check()['users'] ?? '',
        );
    }

    public function testALockedUserIsNoProblem(): void
    {
        $api = $this->api([self::user(false, [27]), self::issues(61205), new Response(403)]);

        self::assertArrayNotHasKey('users', $this->login($api, self::lastFetched())->check());
    }

    private function login(MockApi $api, ?User $lastFetched = null): ForgeLogin
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('lastFetched')->willReturn($lastFetched);

        $issues = new ForgeIssueList(new ForgeIssueApi($api->client, $api->client), 27);

        return new ForgeLogin($issues, $api->client, $users, 27);
    }

    private static function lastFetched(): User
    {
        return new User(37503, 'Jane Doe', fetchedOn: new \DateTimeImmutable('2026-11-20 10:00:00'));
    }

    private static function issues(int $count): Response
    {
        return new Response(200, [], json_encode(['issues' => [], 'total_count' => $count], JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<\Psr\Http\Message\ResponseInterface> $responses
     */
    private function api(array $responses): MockApi
    {
        $recorder = new CommandRunRecorder($this->createStub(Connection::class), new NullLogger());

        return new MockApi($responses, $recorder);
    }

    /**
     * @param list<int> $projectIds
     */
    private static function user(bool $admin, array $projectIds): Response
    {
        $memberships = array_map(
            static fn (int $id): array => ['project' => ['id' => $id, 'name' => 'Project ' . $id]],
            $projectIds,
        );
        $user = [
            'login' => 'benjaminkott',
            'firstname' => 'Benjamin',
            'lastname' => 'Kott',
            'admin' => $admin,
            'memberships' => $memberships,
        ];

        return new Response(200, [], json_encode(['user' => $user], JSON_THROW_ON_ERROR));
    }
}
