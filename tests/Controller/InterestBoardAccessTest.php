<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Controller;

use App\Controller\InterestBoardController;
use App\Entity\Interest;
use App\Repository\InterestRepository;
use App\Sprint\ReviewBoards;
use App\Sprint\SprintBoards;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The board of an interest is private: it looks the interest up by its owner, the logged-in user, so the interest
 * of someone else is not found. The routes also need ROLE_USER, see the attribute of the controller.
 */
final class InterestBoardAccessTest extends TestCase
{
    public function testTheInterestOfSomeoneElseIsNotFound(): void
    {
        $interests = $this->createMock(InterestRepository::class);
        // The repository only finds interests of the owner it is asked for
        $interests->method('ofOwnerById')->willReturnCallback(
            static fn (string $owner, int $id): ?Interest => 'owner' === $owner && 4 === $id
                ? new Interest('owner', 'Backend UI', [], [], new \DateTimeImmutable())
                : null,
        );
        $interests->expects(self::atLeastOnce())->method('ofOwnerById')->with('visitor', 4);

        $this->expectException(NotFoundHttpException::class);
        $this->controller($interests, 'visitor')->columns(new Request(), 4, 'reviews');
    }

    public function testTheBoardAsksForTheUserLoggedIn(): void
    {
        $interests = $this->createMock(InterestRepository::class);
        $interests->expects(self::once())->method('ofOwnerById')->with('visitor', 7)->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->controller($interests, 'visitor')->board(new Request(), 7);
    }

    public function testTheRoutesNeedALogin(): void
    {
        $attributes = (new \ReflectionClass(InterestBoardController::class))->getAttributes(IsGranted::class);

        self::assertCount(1, $attributes);
        self::assertSame('ROLE_USER', $attributes[0]->getArguments()[0]);
    }

    private function controller(InterestRepository $interests, string $user): InterestBoardController
    {
        // The boards are final; an interest that is not found never reaches them
        $controller = new InterestBoardController(
            $interests,
            (new \ReflectionClass(SprintBoards::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(ReviewBoards::class))->newInstanceWithoutConstructor(),
        );
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($user, null, ['ROLE_USER']), 'main'));
        $container = new Container();
        $container->set('security.token_storage', $tokens);
        $controller->setContainer($container);

        return $controller;
    }
}
