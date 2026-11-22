<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\StreamKindEnum;
use App\Enum\StreamSourceEnum;

/**
 * What the activity stream shows: the events of a time window, of a channel, of some kinds, of a branch, of a
 * person, with or without the bots. The page and the API build it, StreamQuery and StreamInsightsQuery search it.
 */
final readonly class StreamFilter
{
    /**
     * @param list<StreamKindEnum> $kinds     any of them, all without one
     * @param list<int>            $forgeIds  the person by the numbers on Forge, see UserDirectory::numbersOf()
     * @param list<int>            $gerritIds the person by the numbers on Gerrit
     * @param list<string>         $nameWords the person by words of the name, when no number is known
     */
    public function __construct(
        public ?StreamWindow $window = null,
        public ?StreamSourceEnum $source = null,
        public array $kinds = [],
        public ?string $branch = null,
        public array $forgeIds = [],
        public array $gerritIds = [],
        public array $nameWords = [],
        public bool $bots = false,
    ) {
    }

    public function withWindow(StreamWindow $window): self
    {
        return new self(
            $window,
            $this->source,
            $this->kinds,
            $this->branch,
            $this->forgeIds,
            $this->gerritIds,
            $this->nameWords,
            $this->bots,
        );
    }

    public function hasPerson(): bool
    {
        return [] !== $this->forgeIds || [] !== $this->gerritIds || [] !== $this->nameWords;
    }

    public function hasKind(StreamKindEnum $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }
}
