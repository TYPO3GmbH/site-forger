<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Setup;

use App\Enum\SystemCheckEnum;
use App\Setup\SetupCheck;
use App\Setup\SetupSetting;
use PHPUnit\Framework\TestCase;

final class SetupCheckTest extends TestCase
{
    public function testAMissingTokenIsAProblem(): void
    {
        $settings = self::byName(SetupCheck::settings(['APP_SECRET' => 'abc', 'FORGE_ACCESS_TOKEN' => ' '], []));

        self::assertSame(SystemCheckEnum::OK, $settings['APP_SECRET']->result);
        self::assertSame(SystemCheckEnum::PROBLEM, $settings['FORGE_ACCESS_TOKEN']->result);
        self::assertSame('Not set.', $settings['FORGE_ACCESS_TOKEN']->detail);
    }

    public function testThePlaceholderOfDotEnvIsNotSet(): void
    {
        $settings = self::byName(SetupCheck::settings(
            ['KEYCLOAK_CLIENT_SECRET' => 'blabla', 'KEYCLOAK_CLIENT_ID' => 'forger-typo3-com'],
            ['KEYCLOAK_CLIENT_SECRET' => 'blabla', 'KEYCLOAK_CLIENT_ID' => 'forger-typo3-com'],
        ));

        self::assertSame(SystemCheckEnum::PROBLEM, $settings['KEYCLOAK_CLIENT_SECRET']->result);
        self::assertSame('Still the placeholder from .env.', $settings['KEYCLOAK_CLIENT_SECRET']->detail);
        // The client ID is no secret: the committed .env holds the real one
        self::assertSame(SystemCheckEnum::OK, $settings['KEYCLOAK_CLIENT_ID']->result);
    }

    public function testTheForgeTokenNamesTheUserForgeLogsInAs(): void
    {
        $environment = ['FORGE_ACCESS_TOKEN' => 'abc'];
        $accepted = self::byName(SetupCheck::settings(
            $environment,
            [],
            ['account' => 'benjaminkott (Benjamin Kott)', 'error' => null],
        ))['FORGE_ACCESS_TOKEN'];
        $rejected = self::byName(SetupCheck::settings(
            $environment,
            [],
            ['account' => null, 'error' => 'Forge does not accept the token (401).'],
        ))['FORGE_ACCESS_TOKEN'];

        self::assertSame(SystemCheckEnum::OK, $accepted->result);
        self::assertSame('Logged in as benjaminkott (Benjamin Kott).', $accepted->detail);
        self::assertSame(SystemCheckEnum::PROBLEM, $rejected->result);
        self::assertSame('Forge does not accept the token (401).', $rejected->detail);
    }

    public function testAForgeAccountOutsideTheProjectIsAProblem(): void
    {
        $setting = self::byName(SetupCheck::settings(
            ['FORGE_ACCESS_TOKEN' => 'abc'],
            [],
            [
                'account' => 'typo3com_bamboo',
                'error' => null,
                'project' => 'TYPO3 Core (typo3cms-core)',
                'membersUrl' => 'https://forge.typo3.org/projects/typo3cms-core/settings/members',
            ],
        ))['FORGE_ACCESS_TOKEN'];

        self::assertSame(SystemCheckEnum::PROBLEM, $setting->result);
        self::assertSame('Logged in as typo3com_bamboo.', $setting->detail);
        self::assertStringStartsWith(
            'The account is no member of the Forge project TYPO3 Core (typo3cms-core)',
            (string) $setting->problem,
        );
        self::assertSame(
            ['label' => 'Members in Forge', 'url' => 'https://forge.typo3.org/projects/typo3cms-core/settings/members'],
            $setting->action,
        );
    }

    public function testHiddenIssuesAndUsersAreProblems(): void
    {
        $setting = self::byName(SetupCheck::settings(
            ['FORGE_ACCESS_TOKEN' => 'abc'],
            [],
            [
                'account' => 'typo3com_bamboo',
                'error' => null,
                'issues' => 'Forge refuses the issues of the project (403).',
                'users' => 'Forge hides the user #1 (Jane Doe) from the account (404).',
            ],
        ))['FORGE_ACCESS_TOKEN'];

        self::assertSame(SystemCheckEnum::PROBLEM, $setting->result);
        self::assertSame(
            'Forge refuses the issues of the project (403). Forge hides the user #1 (Jane Doe) from the account (404).',
            $setting->problem,
        );
        self::assertNull($setting->action);
    }

    public function testNoValueLeavesTheCheck(): void
    {
        $settings = SetupCheck::settings(['FORGE_ACCESS_TOKEN' => 'secret-token'], []);

        self::assertStringNotContainsString('secret-token', serialize($settings));
    }

    /**
     * @param list<SetupSetting> $settings
     *
     * @return array<string, SetupSetting>
     */
    private static function byName(array $settings): array
    {
        $byName = [];
        foreach ($settings as $setting) {
            $byName[$setting->name] = $setting;
        }

        return $byName;
    }
}
