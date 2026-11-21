<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\Duplicates\DuplicateHit;

/**
 * Two open issues that may be duplicates, as the duplicate index has them, see DuplicateImport. The similarity is
 * the same from both sides, so each pair is stored once, the lower number first.
 */
final readonly class DuplicatePair
{
    public const string KIND = 'pair';

    /**
     * @param int $fromLower  how similar the higher issue is, seen from the lower one
     * @param int $fromHigher how similar the lower issue is, seen from the higher one
     */
    public function __construct(
        public int $lower,
        public int $higher,
        public int $similarity,
        public int $fromLower,
        public int $fromHigher,
        public string $checkedOn,
    ) {
    }

    /**
     * The pair of an issue and a hit of its search, see DuplicateSearch::find().
     */
    public static function of(int $issue, DuplicateHit $hit, string $checkedOn): self
    {
        $other = $hit->issue->id;
        $forward = (int) $hit->forward;
        $backward = (int) $hit->backward;

        return $issue < $other
            ? new self($issue, $other, (int) $hit->similarity, $forward, $backward, $checkedOn)
            : new self($other, $issue, (int) $hit->similarity, $backward, $forward, $checkedOn);
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromDocument(array $document): self
    {
        $issues = array_values(array_map(intval(...), (array) ($document['issues'] ?? [])));

        return new self(
            $issues[0] ?? 0,
            $issues[1] ?? 0,
            (int) ($document['similarity'] ?? 0),
            (int) ($document['from_lower'] ?? 0),
            (int) ($document['from_higher'] ?? 0),
            (string) ($document['checked_on'] ?? ''),
        );
    }

    public function id(): string
    {
        return sprintf('%s-%d-%d', self::KIND, $this->lower, $this->higher);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        return [
            'kind' => self::KIND,
            'issues' => [$this->lower, $this->higher],
            'similarity' => $this->similarity,
            'from_lower' => $this->fromLower,
            'from_higher' => $this->fromHigher,
            'checked_on' => $this->checkedOn,
        ];
    }
}
