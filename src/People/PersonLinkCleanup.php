<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

use App\Entity\PersonLink;
use App\Repository\PersonLinkRepository;
use App\Repository\UserRepository;

/**
 * Removes the links the sources made needless: Forge or Gerrit told the username of a linked account, and the sync
 * joined the account to the document of the person. The links then show only what an admin decided and no source
 * knows. A link the sources contradict stays: an admin decides, see contradicted().
 */
final readonly class PersonLinkCleanup
{
    public const string STEP = 'Drop the links the sources joined';

    public function __construct(
        private PersonLinkRepository $links,
        private UserRepository $users,
        private UserDirectory $directory,
    ) {
    }

    /**
     * @return int the links removed
     */
    public function removeJoined(): int
    {
        $removed = 0;
        foreach ($this->links->latest() as $link) {
            if (self::isJoined($link, $this->users->byKey($link->getAccount()))) {
                $this->links->unlink($link->getAccount());
                ++$removed;
            }
        }
        if ($removed > 0) {
            $this->directory->forget();
        }

        return $removed;
    }

    /**
     * Whether the account is in the document of the person already: the link adds nothing.
     */
    public static function isJoined(PersonLink $link, ?User $account): bool
    {
        return null !== $account && $account->id() === $link->getPerson();
    }

    /**
     * The username the sources give an account on Forge or Gerrit, when it is another than the person of the link.
     * Null when the sources agree or do not know. A link of a TYPO3 account, like one renamed to "old_…", joins two
     * usernames on purpose: it is no contradiction.
     */
    public static function contradicted(PersonLink $link, ?User $account): ?string
    {
        $byNumber = 1 === preg_match('/^(forge|gerrit):\d+$/', $link->getAccount());
        if (!$byNumber || null === $account?->username || $account->username === $link->getPerson()) {
            return null;
        }

        return $account->username;
    }
}
