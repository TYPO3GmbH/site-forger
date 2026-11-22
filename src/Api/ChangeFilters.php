<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api;

use App\Enum\ChangeListEnum;
use App\Enum\ChangeStateFilterEnum;
use Symfony\Component\HttpFoundation\Request;

/**
 * The filters of /api/v1/changes besides the search words, see ApiChangeQuery.
 *
 * The owner is filtered by the number on Gerrit or by words of the name, see PersonFilter. The days are whole days
 * in UTC, "since" includes the day and "before" leaves it out.
 */
final readonly class ChangeFilters
{
    /**
     * Gerrit counts no change bigger than this, it is the most "maxLines" takes.
     */
    public const int MAX_LINES = 1000000;

    /**
     * @param string|null       $branch   the target branch, like "main" or "13.4"
     * @param string|null       $path     the files or folders of the repository the changes touch, each of them,
     *                                    by commas, "*" for any characters
     * @param PersonFilter|null $owner    who uploaded the changes
     * @param int|null          $maxLines the most lines a change adds and removes together
     */
    public function __construct(
        public ChangeStateFilterEnum $state = ChangeStateFilterEnum::OPEN,
        public ?ChangeListEnum $list = null,
        public ?string $branch = null,
        public ?string $path = null,
        public ?PersonFilter $owner = null,
        public ?int $maxLines = null,
        public ?\DateTimeImmutable $createdSince = null,
        public ?\DateTimeImmutable $createdBefore = null,
        public ?\DateTimeImmutable $updatedSince = null,
        public ?\DateTimeImmutable $updatedBefore = null,
        public ?\DateTimeImmutable $closedSince = null,
        public ?\DateTimeImmutable $closedBefore = null,
    ) {
    }

    /**
     * @throws InvalidParameter
     */
    public static function fromRequest(Request $request): self
    {
        $closedSince = ListParameters::day($request, 'closedSince');
        $closedBefore = ListParameters::day($request, 'closedBefore');
        // Asked by the day they closed, the changes are merged or abandoned ones
        $byClosing = null !== $closedSince || null !== $closedBefore;
        $default = $byClosing ? ChangeStateFilterEnum::ALL : ChangeStateFilterEnum::OPEN;
        $state = ListParameters::choice($request, 'status', ChangeStateFilterEnum::class, $default);
        $list = null === ListParameters::optional($request, 'list')
            ? null
            : ListParameters::choice($request, 'list', ChangeListEnum::class, ChangeListEnum::READY);

        return new self(
            state: $state,
            list: $list,
            branch: ListParameters::optional($request, 'branch'),
            path: ListParameters::optional($request, 'path'),
            owner: ListParameters::person($request, 'owner'),
            maxLines: null === ListParameters::optional($request, 'maxLines')
                ? null
                : ListParameters::number($request, 'maxLines', 0, 0, self::MAX_LINES),
            createdSince: ListParameters::day($request, 'createdSince'),
            createdBefore: ListParameters::day($request, 'createdBefore'),
            updatedSince: ListParameters::day($request, 'updatedSince'),
            updatedBefore: ListParameters::day($request, 'updatedBefore'),
            closedSince: $closedSince,
            closedBefore: $closedBefore,
        );
    }
}
