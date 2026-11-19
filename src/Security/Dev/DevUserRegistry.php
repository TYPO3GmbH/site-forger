<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Security\Dev;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use T3G\Bundle\Keycloak\Security\KeyCloakUser;

/**
 * Fixed users for logging in without Keycloak, configured in "app.dev_login.users" (dev only).
 *
 * The users are KeyCloakUser instances, so the Keycloak user provider refreshes them
 * like any other user and the rest of the application does not need to know about them.
 */
#[When(env: 'dev')]
final readonly class DevUserRegistry
{
    private const string DEFAULT_ROLE = 'ROLE_USER';

    /**
     * @param array<string, array{name: string, roles: list<string>}> $users
     */
    public function __construct(
        #[Autowire(param: 'app.dev_login.users')]
        private array $users,
    ) {
    }

    /**
     * @return array<string, array{name: string, roles: list<string>}>
     */
    public function all(): array
    {
        return $this->users;
    }

    public function load(string $identifier): KeyCloakUser
    {
        $user = $this->users[$identifier] ?? throw new UserNotFoundException(sprintf('There is no dev user "%s".', $identifier));
        $roles = array_values(array_unique([...$user['roles'], self::DEFAULT_ROLE]));

        return new KeyCloakUser($identifier, $roles, $identifier . '@forger.ddev.site', $user['name'], true);
    }
}
