<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

use App\Controller\PeopleController;
use App\Enum\LinkReasonEnum;
use App\People\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * The people for the admins as PeopleController passes them: the tab of the Forge accounts without a username.
 */
final class PeopleTemplateTest extends KernelTestCase
{
    use KnowsNoPeople;

    public function testTheAccountsWithoutAUsernameAreListedByWhy(): void
    {
        $html = $this->renderWithoutUsername(self::accounts(), 3);

        self::assertMatchesRegularExpression(
            '/Forge: unknown.*Not found \(404\).*Forge: not asked yet.*Not asked yet.*Gerrit.*gerrit:9033/s',
            $html,
        );
        self::assertStringContainsString('No username <span class="chip-count">3</span>', $html);
    }

    public function testEachAccountCanBeAskedAndLinked(): void
    {
        $html = $this->renderWithoutUsername(self::accounts(), 2);

        self::assertSame(3, preg_match_all('#action="/management/people/[^"]+/refresh"#', $html));
        self::assertStringNotContainsString('name="back"', $html);
        self::assertStringContainsString('action="/management/people/gerrit:9033/refresh"', $html);
        self::assertStringContainsString('<input type="hidden" name="source" value="gerrit">', $html);
        self::assertStringContainsString('action="/management/people/forge:45758/refresh"', $html);
        self::assertStringContainsString('href="/management/people/forge:45758#people-link"', $html);
        self::assertStringContainsString('href="/management/people/forge:45755#people-link"', $html);
    }

    public function testAGroupIsAskedForAtOnceAndTheAccountsThatWaitAreMarked(): void
    {
        $html = $this->renderWithoutUsername(self::accounts(), 3, ['forge:45758' => true]);

        self::assertStringContainsString('<input type="hidden" name="group" value="gone">', $html);
        self::assertStringContainsString('<input type="hidden" name="group" value="not-asked">', $html);
        self::assertStringContainsString('Ask Gerrit for all', $html);
        self::assertMatchesRegularExpression('/1\s+account waits/', $html);
        self::assertSame(1, substr_count($html, 'Waits for Forge'));
    }

    public function testMoreAccountsAreShownOnRequest(): void
    {
        $html = $this->renderWithoutUsername(
            [new User(1, 'One', fetchedOn: self::now(), gone: true, goneStatus: 404)],
            51,
        );

        self::assertStringContainsString('1 of 51', $html);
        self::assertStringContainsString('/management/people?view=no-username&amp;limit=100', $html);
    }

    /**
     * @param list<User>          $withoutUsername
     * @param array<string, true> $requested
     */
    private function renderWithoutUsername(array $withoutUsername, int $count, array $requested = []): string
    {
        self::bootKernel();
        self::knowNoPeople();
        $container = self::getContainer();
        $request = Request::create('/management/people?view=no-username');
        // The forms carry a CSRF token, kept in the session
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        /** @var Environment $twig */
        $twig = $container->get('twig');

        // The content only: the layout around reads the menu from the database
        return $twig->load('management/people/index.html.twig')->renderBlock('body', [
            'view' => 'no-username',
            'links' => [],
            'linkedCount' => 0,
            'withoutUsername' => $withoutUsername,
            'withoutUsernameCount' => $count,
            'groups' => array_combine(
                array_map(static fn (User $user): string => $user->id(), $withoutUsername),
                array_map(PeopleController::groupOf(...), $withoutUsername),
            ),
            'requested' => $requested,
            'requestedPerRun' => 100,
            'suggestions' => [],
            'total' => 0,
            'reason' => LinkReasonEnum::RENAMED,
            'reasons' => LinkReasonEnum::cases(),
            'counts' => ['renamed' => 0, 'same-name' => 0],
            'limit' => 50,
            'more' => 50,
            'activity' => [],
            'csrfTokenId' => 'person_link',
        ]);
    }

    /**
     * @return list<User> one unknown to Forge, one Forge was not asked for, one of Gerrit only
     */
    private static function accounts(): array
    {
        return [
            new User(45758, 'Andreas Lindner', fetchedOn: self::now(), gone: true, goneStatus: 404),
            new User(45755, 'Adrian Widmer'),
            new User(null, '', gerritId: 9033, gerritName: 'anonymized-52878'),
        ];
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-20 18:34:00', new \DateTimeZone('UTC'));
    }
}
