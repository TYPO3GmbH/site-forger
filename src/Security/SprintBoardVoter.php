<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Security;

use App\Entity\SprintBoard;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who sees a sprint board: everybody a public one, a restricted one only the people it is shared with, the
 * managers and the admins. Pages that ask answer 404 to the others, so a restricted board does not show it exists.
 *
 * @extends Voter<string, SprintBoard>
 */
final class SprintBoardVoter extends Voter
{
    public const string VIEW = 'SPRINT_BOARD_VIEW';
    public const string MANAGER = 'ROLE_FORGER_MANAGEMENT';

    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && $subject instanceof SprintBoard;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        if (!$subject->isRestricted() || $this->decisions->decide($token, [self::MANAGER])) {
            return true;
        }
        $user = $token->getUser();

        return null !== $user && $subject->hasMember($user->getUserIdentifier());
    }
}
