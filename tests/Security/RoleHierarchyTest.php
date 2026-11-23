<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use T3G\Bundle\Keycloak\Security\KeyCloakUserProvider;

/**
 * Which role each Keycloak group gets, and which roles a role reaches through `role_hierarchy`.
 */
final class RoleHierarchyTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function keycloakGroups(): iterable
    {
        yield 'the TYPO3 GmbH' => ['typo3.com-gmbh', 'ROLE_ADMIN'];
        yield 'the Forger management' => ['typo3.org.ecosystem.forger.management', 'ROLE_FORGER_MANAGEMENT'];
        yield 'the component mergers' => ['typo3.org.team.core.component', 'ROLE_FORGER_MANAGEMENT'];
        yield 'the framework mergers' => ['typo3.org.team.core.framework', 'ROLE_FORGER_MANAGEMENT'];
    }

    #[DataProvider('keycloakGroups')]
    public function testGroupGetsItsRole(string $group, string $role): void
    {
        self::bootKernel();
        /** @var KeyCloakUserProvider $provider */
        $provider = self::getContainer()->get('keycloak.typo3.com.user.provider');

        $user = $provider->loadUserByIdentifier('jane', [$group]);

        self::assertSame([$role, 'ROLE_USER'], $user->getRoles());
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function roles(): iterable
    {
        yield 'the Forger management' => ['ROLE_FORGER_MANAGEMENT', ['ROLE_USER'], ['ROLE_ADMIN']];
        yield 'an admin' => ['ROLE_ADMIN', ['ROLE_USER', 'ROLE_FORGER_MANAGEMENT'], []];
    }

    /**
     * @param list<string> $reached
     * @param list<string> $notReached
     */
    #[DataProvider('roles')]
    public function testRoleReachesTheRolesBelowIt(string $role, array $reached, array $notReached): void
    {
        self::bootKernel();
        /** @var RoleHierarchyInterface $hierarchy */
        $hierarchy = self::getContainer()->get('security.role_hierarchy');

        $roles = $hierarchy->getReachableRoleNames([$role]);

        foreach ($reached as $expected) {
            self::assertContains($expected, $roles);
        }
        foreach ($notReached as $unexpected) {
            self::assertNotContains($unexpected, $roles);
        }
    }
}
