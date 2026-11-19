<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Controller;

use App\Controller\IssueRefreshController;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/**
 * Who sees the refresh button of an issue, and where the refresh leads back to.
 */
final class IssueRefreshTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function targets(): iterable
    {
        yield 'a page of the site' => ['/search?query=cache&page=2', true];
        yield 'the dashboard' => ['/', true];
        yield 'another site' => ['https://example.org/', false];
        yield 'another site without the scheme' => ['//example.org/', false];
        yield 'another site with a backslash' => ['/\\example.org/', false];
        yield 'a header in the path' => ["/search\r\nLocation: https://example.org/", false];
        yield 'nothing' => ['', false];
    }

    #[DataProvider('targets')]
    public function testOnlyPathsOfTheSiteAreFollowed(string $target, bool $followed): void
    {
        self::assertSame($followed, IssueRefreshController::isLocalPath($target));
    }

    /**
     * @return iterable<string, array{list<string>|null, bool}>
     */
    public static function users(): iterable
    {
        yield 'not logged in' => [null, false];
        yield 'a user' => [['ROLE_USER'], false];
        yield 'a component merger' => [['ROLE_CORE_COMPONENT'], false];
        yield 'a framework merger' => [['ROLE_CORE_FRAMEWORK'], false];
        yield 'an admin' => [['ROLE_ADMIN'], true];
    }

    /**
     * @param list<string>|null $roles
     */
    #[DataProvider('users')]
    public function testOnlyAdminsSeeTheButton(?array $roles, bool $shown): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $request = Request::create('/search?query=cache');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        if (null !== $roles) {
            $container->get('security.token_storage')->setToken(new UsernamePasswordToken(new InMemoryUser('jane', null, $roles), 'main', $roles));
        }
        /** @var Environment $twig */
        $twig = $container->get('twig');

        $html = $twig->createTemplate("{% import '_macros/issue.html.twig' as issueActions %}{{ issueActions.refresh(91089) }}")->render();

        if ($shown) {
            self::assertStringContainsString('action="/issue/91089/refresh" method="post"', $html);
            self::assertStringContainsString('name="_target_path" value="/search?query=cache"', $html);
            self::assertMatchesRegularExpression('/name="_token" value="[^"]+"/', $html);
        } else {
            self::assertStringNotContainsString('issue-refresh', $html);
        }
    }

    public function testTheRoleOfTheButtonIsTheRoleOfTheRefresh(): void
    {
        self::assertStringContainsString(
            "is_granted('" . IssueRefreshController::ROLE . "')",
            (string) file_get_contents(__DIR__ . '/../../templates/_macros/issue.html.twig')
        );
    }
}
