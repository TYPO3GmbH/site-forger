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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The public API is stateless. No firewall may read the session there, or a session cookie breaks a request in dev
 * and makes the answer private.
 */
final class ApiFirewallTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'the issues of the API' => ['/api/v1/issues', false];
        yield 'a change of the API' => ['/api/v1/changes/12345', false];
        yield 'the documentation of the API' => ['/api/doc', true];
        yield 'the dashboard' => ['/', true];
        yield 'a path that only starts like the API' => ['/api/v10', true];
    }

    #[DataProvider('paths')]
    public function testOnlyTheApiRunsWithoutSecurity(string $path, bool $secured): void
    {
        $firewallMap = self::getContainer()->get('security.firewall.map');

        $request = Request::create($path);
        // The listeners of a firewall ask the request stack for the session when the map builds them.
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);

        $config = $firewallMap->getFirewallConfig($request);

        self::assertNotNull($config);
        self::assertSame($secured, $config->isSecurityEnabled());
    }
}
