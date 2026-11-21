<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\Enum\ReviewStatusEnum;

/**
 * A change of the relation chain of an open change, see GerritChangeImporter::chain(): the change itself, one it is
 * based on, or one based on it.
 */
final readonly class ChainLink
{
    /**
     * @param int $patchSet        the patch set of the change in the chain
     * @param int $currentPatchSet the current patch set of the change
     */
    public function __construct(
        public int $number,
        public int $patchSet,
        public int $currentPatchSet,
        public ?ReviewStatusEnum $status,
        public string $subject,
    ) {
    }

    /**
     * @param array<string, mixed> $link a change of "chain"
     */
    public static function fromDocument(array $link): self
    {
        return new self(
            number: (int) ($link['number'] ?? 0),
            patchSet: (int) ($link['patch_set'] ?? 0),
            currentPatchSet: (int) ($link['current_patch_set'] ?? 0),
            status: ReviewStatusEnum::tryFrom((string) ($link['status'] ?? '')),
            subject: (string) ($link['subject'] ?? ''),
        );
    }

    /**
     * Whether the chain has an older patch set of the change than its current one: the changes above it need a
     * rebase onto it. The index knows a newer patch set than the chain when the change got one since.
     *
     * @param Change|null $indexed the change as the index has it
     */
    public function isOutdated(?Change $indexed = null): bool
    {
        return $this->patchSet < max($this->currentPatchSet, (int) $indexed?->patchsets);
    }
}
