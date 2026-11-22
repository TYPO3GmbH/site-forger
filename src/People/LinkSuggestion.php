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

/**
 * An account that may belong to a person, for an admin to check and link, see LinkSuggestions.
 */
final readonly class LinkSuggestion
{
    public function __construct(
        /** The document of the account, see User::id() */
        public User $account,
        /** The document of the person, with a TYPO3 username */
        public User $person,
        public LinkReasonEnum $reason,
    ) {
    }

    /**
     * Whether both have the same name, without regard to case, spaces and accents: likely one person. A renamed
     * account of another name may be someone else.
     */
    public function sameName(): bool
    {
        $names = array_map(self::normalized(...), $this->person->names());

        return [] !== array_intersect(array_map(self::normalized(...), $this->account->names()), $names);
    }

    private static function normalized(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(false === $ascii ? $name : $ascii));
    }
}
