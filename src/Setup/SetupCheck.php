<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Setup;

use App\Enum\SystemCheckEnum;

/**
 * Whether the tokens and secrets Forger needs are set in the environment, see SetupSetting.
 *
 * A secret that is still the value of the committed .env is a placeholder, not set: .env.local or the server sets
 * the real one. The client ID is no secret, so the committed .env holds the real one.
 */
final class SetupCheck
{
    /**
     * The settings Forger needs, by name, with what they are used for.
     */
    private const array SETTINGS = [
        'APP_SECRET' => 'Sessions and form tokens',
        'FORGE_ACCESS_TOKEN' => 'Users from Forge',
        'KEYCLOAK_CLIENT_ID' => 'Keycloak client for the login with the TYPO3 account',
        'KEYCLOAK_CLIENT_SECRET' => 'Secret of the Keycloak client for the login',
    ];

    /**
     * The settings whose value in the committed .env is a placeholder.
     */
    private const array SECRETS = ['APP_SECRET', 'FORGE_ACCESS_TOKEN', 'KEYCLOAK_CLIENT_SECRET'];

    /**
     * $environment holds the variables of the environment, like $_SERVER. $defaults holds the values of the committed
     * .env. $forge tells who Forge logs in as with the token, see ForgeLogin. It is null if Forge was not asked.
     *
     * @param array<string, mixed>                                                                        $environment
     * @param array<string, string>                                                                       $defaults
     * @param array{account: string|null, error: string|null, project?: string, membersUrl?: string}|null $forge
     *
     * @return list<SetupSetting>
     */
    public static function settings(array $environment, array $defaults, ?array $forge = null): array
    {
        $settings = [];
        foreach (self::SETTINGS as $name => $usedFor) {
            $default = in_array($name, self::SECRETS, true) ? trim($defaults[$name] ?? '') : '';
            $value = $environment[$name] ?? '';
            $value = is_scalar($value) ? trim((string) $value) : '';
            $missing = match (true) {
                '' === $value => 'Not set.',
                '' !== $default && $value === $default => 'Still the placeholder from .env.',
                default => null,
            };
            if (null !== $missing) {
                $settings[] = new SetupSetting($name, $usedFor, SystemCheckEnum::PROBLEM, $missing);
            } elseif ('FORGE_ACCESS_TOKEN' === $name && null !== $forge) {
                // Of the Forge token, the login tells whether Forge accepts it. Of the others, only being set counts.
                $settings[] = self::forgeToken($usedFor, $forge);
            } else {
                $settings[] = new SetupSetting($name, $usedFor, SystemCheckEnum::OK);
            }
        }

        return $settings;
    }

    /**
     * The Forge token is a problem if Forge does not accept it, or if its account may not see the users.
     *
     * @param array{account: string|null, error: string|null, project?: string, membersUrl?: string} $forge
     */
    private static function forgeToken(string $usedFor, array $forge): SetupSetting
    {
        $name = 'FORGE_ACCESS_TOKEN';
        if (null === $forge['account']) {
            return new SetupSetting($name, $usedFor, SystemCheckEnum::PROBLEM, $forge['error']);
        }
        $loggedIn = 'Logged in as ' . $forge['account'] . '.';
        if (!isset($forge['project'])) {
            return new SetupSetting($name, $usedFor, SystemCheckEnum::OK, $loggedIn);
        }

        return new SetupSetting(
            $name,
            $usedFor,
            SystemCheckEnum::PROBLEM,
            $loggedIn,
            sprintf(
                'The account is no member of the Forge project %s, so Forge hides most users from it.'
                . ' Add it to the project with any role, for example "Bot".',
                $forge['project'],
            ),
            isset($forge['membersUrl']) ? ['label' => 'Members in Forge', 'url' => $forge['membersUrl']] : null,
        );
    }
}
