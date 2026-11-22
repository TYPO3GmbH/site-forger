<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

use App\Enum\LinkReasonEnum;
use App\Repository\PersonLinkDismissalRepository;
use App\Repository\PersonLinkRepository;
use App\Repository\UserRepository;

/**
 * Accounts that may belong to another person, for the admins to check: nothing is linked without a click, see
 * App\Controller\PeopleController. A TYPO3 account renamed to "old_<username>" beside the account of the username,
 * and an account without a username that has the name of one person with a username. The accounts linked already
 * and the suggestions an admin turned down are left out, see App\Entity\PersonLinkDismissal.
 */
final readonly class LinkSuggestions
{
    private const string OLD_PREFIX = 'old_';

    /**
     * The names shared by several documents it looks at.
     */
    private const int NAMES = 1000;

    public function __construct(
        private UserRepository $users,
        private PersonLinkRepository $links,
        private PersonLinkDismissalRepository $dismissals,
    ) {
    }

    /**
     * All suggestions to check: those of the same name first, as they likely are one person, then by the name.
     *
     * @return list<LinkSuggestion>
     */
    public function suggestions(): array
    {
        $linked = $this->links->persons();
        $dismissed = $this->dismissals->pairs();
        $suggestions = array_values(array_filter(
            [...$this->renamed(), ...$this->sameName()],
            static fn (LinkSuggestion $suggestion): bool => !isset($linked[$suggestion->account->id()])
                && !isset($dismissed[$suggestion->account->id() . ' ' . $suggestion->person->id()]),
        ));
        usort($suggestions, static fn (LinkSuggestion $a, LinkSuggestion $b): int => [
            !$a->sameName(),
            $a->person->displayName(),
        ] <=> [
            !$b->sameName(),
            $b->person->displayName(),
        ]);

        return $suggestions;
    }

    /**
     * The accounts renamed to "old_<username>" beside the account of the username.
     *
     * @return list<LinkSuggestion>
     */
    private function renamed(): array
    {
        $old = $this->users->withUsernamePrefix(self::OLD_PREFIX, self::NAMES);
        $people = $this->users->byIds(array_map(
            static fn (User $user): string => substr((string) $user->username, strlen(self::OLD_PREFIX)),
            $old,
        ));
        $suggestions = [];
        foreach ($old as $account) {
            $person = $people[substr((string) $account->username, strlen(self::OLD_PREFIX))] ?? null;
            if (null !== $person) {
                $suggestions[] = new LinkSuggestion($account, $person, LinkReasonEnum::RENAMED);
            }
        }

        return $suggestions;
    }

    /**
     * The accounts without a username of the name of one person with a username. Not the accounts Forge was not
     * asked for yet: the sync gives them their username.
     *
     * @return list<LinkSuggestion>
     */
    private function sameName(): array
    {
        $suggestions = [];
        foreach ($this->users->sharingNames(self::NAMES) as $documents) {
            $named = array_values(array_filter($documents, static fn (User $user): bool => null !== $user->username));
            if (1 !== count($named)) {
                continue;
            }
            foreach ($documents as $account) {
                // Forge tells the username of an account it was not asked for yet: the sync joins it then
                $waiting = null !== $account->forgeId && null === $account->fetchedOn;
                if (null === $account->username && !$waiting) {
                    $suggestions[] = new LinkSuggestion($account, $named[0], LinkReasonEnum::SAME_NAME);
                }
            }
        }

        return $suggestions;
    }
}
