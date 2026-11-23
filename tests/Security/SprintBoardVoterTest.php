<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Security;

use App\Entity\SprintBoard;
use App\Security\SprintBoardVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Who sees a sprint board, see SprintBoardVoter.
 */
final class SprintBoardVoterTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, ?string, bool, int}>
     */
    public static function viewers(): iterable
    {
        yield 'a public board, without a login' => [false, null, false, VoterInterface::ACCESS_GRANTED];
        yield 'a public board, logged in' => [false, 'someone', false, VoterInterface::ACCESS_GRANTED];
        yield 'a restricted board, without a login' => [true, null, false, VoterInterface::ACCESS_DENIED];
        yield 'a restricted board, not shared' => [true, 'someone', false, VoterInterface::ACCESS_DENIED];
        yield 'a restricted board, shared' => [true, 'BenjaminKott', false, VoterInterface::ACCESS_GRANTED];
        yield 'a restricted board, a manager' => [true, 'manager', true, VoterInterface::ACCESS_GRANTED];
    }

    #[DataProvider('viewers')]
    public function testWhoSeesTheBoard(bool $restricted, ?string $user, bool $manager, int $expected): void
    {
        $board = (new SprintBoard())->setRestricted($restricted);
        $board->addMember('benjaminkott', new \DateTimeImmutable());
        $decisions = $this->createStub(AccessDecisionManagerInterface::class);
        $decisions->method('decide')->willReturn($manager);
        $token = null === $user ? new NullToken() : new UsernamePasswordToken(new InMemoryUser($user, null), 'main');

        self::assertSame($expected, $this->vote($decisions, $token, $board, SprintBoardVoter::VIEW));
    }

    public function testItVotesOnlyOnTheViewOfABoard(): void
    {
        $decisions = $this->createStub(AccessDecisionManagerInterface::class);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->vote($decisions, new NullToken(), new SprintBoard(), 'ROLE_FORGER_MANAGEMENT'),
        );
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->vote($decisions, new NullToken(), new \stdClass(), SprintBoardVoter::VIEW),
        );
    }

    public function testAMemberCountsOnceAndGoesAgain(): void
    {
        $board = new SprintBoard();
        $board->addMember('BenjaminKott', new \DateTimeImmutable());
        $board->addMember('benjaminkott', new \DateTimeImmutable());

        self::assertCount(1, $board->getMembers());
        self::assertTrue($board->hasMember('BENJAMINKOTT'));

        $board->removeMember('BenjaminKott');

        self::assertCount(0, $board->getMembers());
    }

    public function testTheFormSetsExactlyItsPeople(): void
    {
        $board = new SprintBoard();
        $board->addMember('benjaminkott', new \DateTimeImmutable());
        $board->addMember('just2b', new \DateTimeImmutable());

        $board->setMembers(['Just2B', 'benni'], new \DateTimeImmutable());

        self::assertFalse($board->hasMember('benjaminkott'));
        self::assertTrue($board->hasMember('just2b'));
        self::assertTrue($board->hasMember('benni'));
        self::assertCount(2, $board->getMembers());
    }

    private function vote(
        AccessDecisionManagerInterface $decisions,
        TokenInterface $token,
        mixed $subject,
        string $attribute,
    ): int {
        return (new SprintBoardVoter($decisions))->vote($token, $subject, [$attribute]);
    }
}
