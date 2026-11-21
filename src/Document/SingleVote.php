<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\Enum\ReviewLabelEnum;
use App\People\Person;

/**
 * One vote on a label of a change, with who gave it and when, see GerritChangeImporter::singleVotes().
 */
final readonly class SingleVote
{
    public function __construct(
        public ReviewLabelEnum $label,
        public int $value,
        public ?Person $account,
        public ?string $date,
    ) {
    }

    /**
     * @param array<string, mixed> $vote a vote of "votes"
     */
    public static function fromDocument(array $vote): ?self
    {
        $label = ReviewLabelEnum::tryFrom((string) ($vote['label'] ?? ''));

        return null === $label ? null : new self(
            $label,
            (int) ($vote['value'] ?? 0),
            Change::account($vote['account'] ?? null),
            isset($vote['date']) ? (string) $vote['date'] : null,
        );
    }
}
