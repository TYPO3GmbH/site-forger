<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\CommandRun;

/**
 * A step of a command run while CommandRunRecorder records it. It changes while the step goes on.
 */
final class RecordedStep
{
    public ?\DateTimeImmutable $endedAt = null;
    public ?int $current = null;
    public ?int $total = null;

    public function __construct(
        public readonly string $name,
        public readonly \DateTimeImmutable $startedAt,
    ) {
    }

    /**
     * @return array{name: string, startedAt: string, endedAt: string|null, current: int|null, total: int|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'startedAt' => $this->startedAt->format(\DATE_ATOM),
            'endedAt' => $this->endedAt?->format(\DATE_ATOM),
            'current' => $this->current,
            'total' => $this->total,
        ];
    }
}
