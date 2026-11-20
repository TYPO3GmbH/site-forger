<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Http;

use App\Http\ApiFailure;
use App\Http\RetryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testAJobWaitsLongerEachTimeUpToFiveMinutes(): void
    {
        $policy = RetryPolicy::job();
        $failure = ApiFailure::status('Forge', 429);

        self::assertSame([10, 20, 40, 80, 160, 300, null], array_map(static fn (int $retry): ?int => $policy->delay($failure, $retry), range(1, 7)));
    }

    public function testAPageWaitsSecondsOnly(): void
    {
        $policy = RetryPolicy::page();
        $failure = ApiFailure::status('Gerrit', 503);

        self::assertSame([1, 2, null], array_map(static fn (int $retry): ?int => $policy->delay($failure, $retry), range(1, 3)));
    }

    public function testTheRetryAfterOfTheApiTakesThePlaceOfThePause(): void
    {
        self::assertSame(7, RetryPolicy::job()->delay(ApiFailure::status('Gerrit', 429, 7), 1));
    }

    public function testARetryAfterLongerThanTheLongestPauseIsNotWaitedFor(): void
    {
        self::assertNull(RetryPolicy::page()->delay(ApiFailure::status('Gerrit', 429, 60), 1));
        self::assertSame(60, RetryPolicy::job()->delay(ApiFailure::status('Gerrit', 429, 60), 1));
    }

    public static function failures(): iterable
    {
        yield 'rate limit' => [ApiFailure::status('Forge', 429), true];
        yield 'server error' => [ApiFailure::status('Forge', 500), true];
        yield 'bad gateway' => [ApiFailure::status('Forge', 502), true];
        yield 'unreachable' => [ApiFailure::unreachable('Forge', new \RuntimeException('timeout')), true];
        yield 'not found' => [ApiFailure::status('Forge', 404), false];
        yield 'forbidden' => [ApiFailure::status('Forge', 403), false];
        yield 'no JSON' => [ApiFailure::unexpected('Forge', 'the issue', '<html>'), false];
    }

    #[DataProvider('failures')]
    public function testOnlyTransientFailuresAreTriedAgain(ApiFailure $failure, bool $retried): void
    {
        self::assertSame($retried, null !== RetryPolicy::job()->delay($failure, 1));
    }

    public function testNoneNeverRetries(): void
    {
        self::assertNull(RetryPolicy::none()->delay(ApiFailure::status('Forge', 429), 1));
    }
}
