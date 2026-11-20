<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http;

use App\CommandRun\CommandRunRecorder;
use App\CommandRun\JobStopped;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\LimiterInterface;

/**
 * Sends the requests of all ApiClient instances. Pages use RetryPolicy::page(). Jobs call retryWith() when they
 * start.
 */
final class ApiRequests
{
    private RetryPolicy $policy;

    /** @var (\Closure(): void)|null */
    private ?\Closure $afterWait = null;

    /** @var array<string, float> by API name */
    private array $lastRequestAt = [];

    /**
     * @param (\Closure(int): void)|null $sleep       sleeps seconds, replaced in tests
     * @param (\Closure(int): void)|null $sleepMicros sleeps microseconds, replaced in tests
     */
    public function __construct(
        private readonly CommandRunRecorder $recorder,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $sleep = null,
        private readonly ?\Closure $sleepMicros = null,
    ) {
        $this->policy = RetryPolicy::page();
    }

    /**
     * @param (\Closure(): void)|null $afterWait for example to refresh the lock of a job
     */
    public function retryWith(RetryPolicy $policy, ?\Closure $afterWait = null): void
    {
        $this->policy = $policy;
        $this->afterWait = $afterWait;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $request sends the request once and throws an ApiFailure if it fails
     *
     * @return T
     *
     * @throws ApiFailure when no retries are left
     * @throws JobStopped when the job is stopped while it waits
     */
    public function send(string $api, \Closure $request, ?LimiterInterface $limiter = null, int $minPauseMs = 0): mixed
    {
        for ($retry = 1;; ++$retry) {
            $limiter?->reserve()->wait();
            $this->keepPause($api, $minPauseMs);
            try {
                return $request();
            } catch (ApiFailure $failure) {
                $seconds = $this->policy->delay($failure, $retry) ?? throw $failure;
                $this->wait($failure, $seconds, $retry);
            } finally {
                $this->lastRequestAt[$api] = microtime(true);
            }
        }
    }

    /**
     * The rate limiter counts in whole seconds and lets a burst of requests through at once.
     */
    private function keepPause(string $api, int $minPauseMs): void
    {
        if ($minPauseMs <= 0 || !isset($this->lastRequestAt[$api])) {
            return;
        }
        $elapsedMicros = (int) ((microtime(true) - $this->lastRequestAt[$api]) * 1_000_000);
        $remainingMicros = $minPauseMs * 1000 - $elapsedMicros;
        if ($remainingMicros > 0) {
            ($this->sleepMicros ?? usleep(...))($remainingMicros);
        }
    }

    private function wait(ApiFailure $failure, int $seconds, int $retry): void
    {
        $reason = sprintf('%s, waiting %d s (attempt %d of %d)', $failure->getMessage(), $seconds, $retry, $this->policy->retries);
        $this->logger->warning($reason, ['api' => $failure->api, 'status' => $failure->status]);
        $this->recorder->waiting($reason);
        $this->recorder->sleep($seconds, $this->sleep);
        if (null !== $this->afterWait) {
            ($this->afterWait)();
        }
    }
}
