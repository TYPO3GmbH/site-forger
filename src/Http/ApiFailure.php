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
 * The only exception of a request to an API. The code is the HTTP status, or 0 without a response.
 */
final class ApiFailure extends \RuntimeException
{
    private function __construct(
        public readonly string $api,
        public readonly int $status,
        public readonly bool $transient,
        public readonly ?int $retryAfter,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function status(string $api, int $status, ?int $retryAfter = null, ?\Throwable $previous = null): self
    {
        $transient = 429 === $status || $status >= 500;
        $retryAfter = $retryAfter > 0 ? $retryAfter : null;
        $message = sprintf('%s responded with status %d', $api, $status);

        return new self($api, $status, $transient, $retryAfter, $message, $previous);
    }

    public static function unreachable(string $api, \Throwable $previous): self
    {
        $message = sprintf('%s could not be reached: %s', $api, $previous->getMessage());

        return new self($api, 0, true, null, $message, $previous);
    }

    /**
     * @param string $expected what was requested, for example "the issue"
     */
    public static function unexpected(string $api, string $expected, mixed $answer, ?\Throwable $previous = null): self
    {
        $answer = is_string($answer) ? mb_substr(trim($answer), 0, 200) : get_debug_type($answer);
        $message = sprintf('%s answered without %s: %s', $api, $expected, $answer);

        return new self($api, 0, false, null, $message, $previous);
    }

    public function isRateLimit(): bool
    {
        return 429 === $this->status;
    }

    /**
     * Forge answers private and locked issues and users with 403.
     */
    public function isNotFound(): bool
    {
        return in_array($this->status, [403, 404, 410], true);
    }
}
