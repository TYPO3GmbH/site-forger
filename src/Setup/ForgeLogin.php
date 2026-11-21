<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Setup;

use App\Http\ApiClient;
use App\Http\ApiFailure;

/**
 * Who Forge logs Forger in as with FORGE_ACCESS_TOKEN, for the system page.
 *
 * Forge answers 401 without a valid token. It still shows public issues then, but no users.
 */
final readonly class ForgeLogin
{
    public function __construct(
        private ApiClient $forge,
    ) {
    }

    /**
     * The account, like "benjaminkott (Benjamin Kott)", or why Forge did not log in, see SetupCheck.
     *
     * @return array{account: string|null, error: string|null}
     */
    public function check(): array
    {
        try {
            $user = $this->forge->object('/users/current.json', [], 'the current user')['user'] ?? null;
        } catch (ApiFailure $e) {
            $error = 401 === $e->status
                ? 'Forge does not accept the token (401).'
                : 'Forge could not be asked: ' . $e->getMessage();

            return ['account' => null, 'error' => $error];
        }
        if (!is_array($user) || !isset($user['login'])) {
            return ['account' => null, 'error' => 'Forge did not name the user.'];
        }
        $name = trim(sprintf('%s %s', $user['firstname'] ?? '', $user['lastname'] ?? ''));

        return ['account' => $user['login'] . ('' === $name ? '' : ' (' . $name . ')'), 'error' => null];
    }
}
