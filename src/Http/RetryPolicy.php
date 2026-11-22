<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http;

/**
 * The delay doubles from $firstDelay up to $maxDelay. A "Retry-After" of the API replaces the delay. If it is
 * longer than $maxDelay, the request is not tried again.
 */
final readonly class RetryPolicy
{
    public function __construct(
        public int $retries,
        public int $firstDelay,
        public int $maxDelay,
    ) {
    }

    /**
     * About 17 minutes in total, so that a full import over night survives a rate limit. A delta keeps its lock
     * while it waits, so the next delta is skipped. The first pause is 30 seconds: Forge counts by the minute, and
     * after 10 seconds it answered 429 again half of the time.
     */
    public static function job(): self
    {
        return new self(6, 30, 300);
    }

    /**
     * A person waits for the page.
     */
    public static function page(): self
    {
        return new self(2, 1, 5);
    }

    public static function none(): self
    {
        return new self(0, 0, 0);
    }

    /**
     * @param int $retry 1 for the first retry
     *
     * @return int|null the seconds to wait, or null to give up
     */
    public function delay(ApiFailure $failure, int $retry): ?int
    {
        if (!$failure->transient || $retry < 1 || $retry > $this->retries) {
            return null;
        }
        $delay = $failure->retryAfter ?? $this->backoff($retry);

        return $delay <= $this->maxDelay ? $delay : null;
    }

    private function backoff(int $retry): int
    {
        return min($this->firstDelay * 2 ** ($retry - 1), $this->maxDelay);
    }
}
