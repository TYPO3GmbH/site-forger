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
    private const string SET = 'set';
    private const string NOT_SET = 'not set';
    private const string PLACEHOLDER = 'still the placeholder from .env';

    /**
     * The settings Forger needs, by name, with what they are used for.
     */
    private const array SETTINGS = [
        'APP_SECRET' => 'Signs sessions and form tokens.',
        'FORGE_ACCESS_TOKEN' => 'Loads the users from Forge. Without it, Forge reports every user as not found.',
        'KEYCLOAK_CLIENT_ID' => 'The ID of the Forger client in Keycloak (login.typo3.com).'
            . ' The login with the TYPO3 account needs it.',
        'KEYCLOAK_CLIENT_SECRET' => 'The secret of the Forger client in Keycloak (login.typo3.com).'
            . ' The login with the TYPO3 account needs it.',
    ];

    /**
     * The settings whose value in the committed .env is a placeholder.
     */
    private const array SECRETS = ['APP_SECRET', 'FORGE_ACCESS_TOKEN', 'KEYCLOAK_CLIENT_SECRET'];

    /**
     * @param array<string, mixed>  $environment the variables of the environment, like $_SERVER
     * @param array<string, string> $defaults    the values of the committed .env
     *
     * @return list<SetupSetting>
     */
    public static function settings(array $environment, array $defaults): array
    {
        $settings = [];
        foreach (self::SETTINGS as $name => $usedFor) {
            $default = in_array($name, self::SECRETS, true) ? trim($defaults[$name] ?? '') : '';
            $value = $environment[$name] ?? '';
            $value = is_scalar($value) ? trim((string) $value) : '';
            $state = match (true) {
                '' === $value => self::NOT_SET,
                '' !== $default && $value === $default => self::PLACEHOLDER,
                default => self::SET,
            };
            $result = self::SET === $state ? SystemCheckEnum::OK : SystemCheckEnum::PROBLEM;
            $settings[] = new SetupSetting($name, $usedFor, $result, $state);
        }

        return $settings;
    }
}
