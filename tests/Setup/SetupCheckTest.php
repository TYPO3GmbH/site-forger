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
        self::assertSame('not set', $settings['FORGE_ACCESS_TOKEN']->state);
    }

    public function testThePlaceholderOfDotEnvIsNotSet(): void
    {
        $settings = self::byName(SetupCheck::settings(
            ['KEYCLOAK_CLIENT_SECRET' => 'blabla', 'KEYCLOAK_CLIENT_ID' => 'forger-typo3-com'],
            ['KEYCLOAK_CLIENT_SECRET' => 'blabla', 'KEYCLOAK_CLIENT_ID' => 'forger-typo3-com'],
        ));

        self::assertSame(SystemCheckEnum::PROBLEM, $settings['KEYCLOAK_CLIENT_SECRET']->result);
        self::assertSame('still the placeholder from .env', $settings['KEYCLOAK_CLIENT_SECRET']->state);
        // The client ID is no secret: the committed .env holds the real one
        self::assertSame(SystemCheckEnum::OK, $settings['KEYCLOAK_CLIENT_ID']->result);
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
