<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Controller;

use App\Controller\RefreshController;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/**
 * Who sees the refresh buttons of issues and changes, and where a refresh leads back to.
 */
final class RefreshTest extends KernelTestCase
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
        self::assertSame($followed, RefreshController::isLocalPath($target));
    }

    /**
     * @return iterable<string, array{list<string>|null, bool}>
     */
    public static function users(): iterable
    {
        yield 'not logged in' => [null, false];
        yield 'a user' => [['ROLE_USER'], false];
        yield 'the Forger management' => [['ROLE_FORGER_MANAGEMENT'], false];
        yield 'an admin' => [['ROLE_ADMIN'], true];
    }

    /**
     * @param list<string>|null $roles
     */
    #[DataProvider('users')]
    public function testOnlyAdminsSeeTheButtons(?array $roles, bool $shown): void
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

        $buttons = $twig->createTemplate("{% import '_macros/refresh.html.twig' as refresh %}{{ refresh.issue(91089) }}{{ refresh.change(95026) }}")->render();
        $form = $twig->render('_partials/refresh_form.html.twig');

        if ($shown) {
            self::assertStringContainsString('form="refresh-form" formaction="/issue/91089/refresh" formmethod="post"', $buttons);
            self::assertStringContainsString('form="refresh-form" formaction="/change/95026/refresh" formmethod="post"', $buttons);
            // No form of their own: a form in another form of the page would be dropped
            self::assertStringNotContainsString('<form', $buttons);
            self::assertStringContainsString('<form id="refresh-form" method="post" hidden>', $form);
            self::assertStringContainsString('name="_target_path" value="/search?query=cache"', $form);
            self::assertMatchesRegularExpression('/name="_token" value="[^"]+"/', $form);
        } else {
            self::assertSame('', trim($buttons));
            self::assertSame('', trim($form));
        }
    }

    public function testTheFormStandsOutsideOfTheFormsOfThePage(): void
    {
        // The javascripts block is the end of the body, after the content of the page
        self::assertMatchesRegularExpression(
            "/\\{% block javascripts %\\}\\s*\\{% include '_partials\\/refresh_form.html.twig' %\\}/",
            (string) file_get_contents(__DIR__ . '/../../templates/base.html.twig')
        );
    }

    public function testTheRoleOfTheButtonsIsTheRoleOfTheRefresh(): void
    {
        foreach (['_macros/refresh.html.twig', '_partials/refresh_form.html.twig'] as $template) {
            self::assertStringContainsString(
                "is_granted('" . RefreshController::ROLE . "')",
                (string) file_get_contents(__DIR__ . '/../../templates/' . $template),
                $template
            );
        }
    }
}
