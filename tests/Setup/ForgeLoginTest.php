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
        $api = $this->api([self::user(false, [27, 1716])]);

        self::assertSame(
            ['account' => 'benjaminkott (Benjamin Kott)', 'error' => null],
            (new ForgeLogin($api->client, 27))->check(),
        );
        self::assertStringContainsString('include=memberships', $api->request(0)->getUri()->getQuery());
    }

    public function testAnAdminNeedsNoMembership(): void
    {
        $api = $this->api([self::user(true, [])]);

        self::assertArrayNotHasKey('project', (new ForgeLogin($api->client, 27))->check());
    }

    public function testAnAccountOutsideTheProjectNamesTheProject(): void
    {
        $project = ['project' => ['id' => 27, 'name' => 'TYPO3 Core', 'identifier' => 'typo3cms-core']];
        $api = $this->api([
            self::user(false, [1716]),
            new Response(200, [], json_encode($project, JSON_THROW_ON_ERROR)),
        ]);

        $login = (new ForgeLogin($api->client, 27))->check();

        self::assertSame('TYPO3 Core (typo3cms-core)', $login['project'] ?? null);
        self::assertSame(
            'https://forge.typo3.org/projects/typo3cms-core/settings/members',
            $login['membersUrl'] ?? null,
        );
        self::assertSame('/projects/27.json', $api->request(1)->getUri()->getPath());
    }

    public function testTheProjectNumberStandsInForAProjectForgeHides(): void
    {
        $api = $this->api([self::user(false, []), new Response(403)]);

        $login = (new ForgeLogin($api->client, 27))->check();

        self::assertSame('#27', $login['project'] ?? null);
        self::assertSame('https://forge.typo3.org/projects/27/settings/members', $login['membersUrl'] ?? null);
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
