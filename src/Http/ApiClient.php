<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * A JSON API. Each API is a service in config/services.yaml.
 */
final readonly class ApiClient
{
    /**
     * Gerrit prefixes its JSON to protect against XSSI.
     */
    private const string XSSI_PREFIX = ")]}'";

    /**
     * @param string $name the name in messages and the key of the rate limiter
     */
    public function __construct(
        public string $name,
        private ClientInterface $client,
        private ApiRequests $requests,
        private ?RateLimiterFactoryInterface $rateLimiter = null,
        private int $minPauseMs = 0,
    ) {
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $query see query()
     *
     * @throws ApiFailure
     */
    public function get(string $path, array $query = []): mixed
    {
        $url = self::url($path, $query);
        $body = $this->requests->send($this->name, fn (): string => $this->fetch($url), $this->rateLimiter?->create($this->name), $this->minPauseMs);

        return $this->decode($body);
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $query
     * @param string                                  $expected what is requested, for example "the issue"
     *
     * @return array<string, mixed>
     *
     * @throws ApiFailure
     */
    public function object(string $path, array $query, string $expected): array
    {
        $answer = $this->get($path, $query);
        if (!is_array($answer)) {
            throw ApiFailure::unexpected($this->name, $expected, $answer);
        }

        return $answer;
    }

    /**
     * Gerrit expects a repeated parameter (o=a&o=b), http_build_query() writes o[0]=a&o[1]=b. Null leaves the
     * parameter out.
     *
     * @param array<string, scalar|list<scalar>|null> $query
     */
    public static function query(array $query): string
    {
        $parts = [];
        foreach ($query as $name => $values) {
            foreach ((array) $values as $value) {
                $parts[] = rawurlencode($name) . '=' . rawurlencode(is_bool($value) ? (string) (int) $value : (string) $value);
            }
        }

        return implode('&', $parts);
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $query
     */
    private static function url(string $path, array $query): string
    {
        $query = self::query($query);
        if ('' === $query) {
            return $path;
        }

        return $path . (str_contains($path, '?') ? '&' : '?') . $query;
    }

    /**
     * @throws ApiFailure
     */
    private function fetch(string $url): string
    {
        try {
            return (string) $this->client->request('GET', $url, ['headers' => ['Accept' => 'application/json']])->getBody();
        } catch (RequestException $e) {
            $response = $e->getResponse() ?? throw ApiFailure::unreachable($this->name, $e);
            $retryAfter = $response->getHeaderLine('Retry-After');

            throw ApiFailure::status($this->name, $response->getStatusCode(), ctype_digit($retryAfter) ? (int) $retryAfter : null, $e);
        } catch (GuzzleException $e) {
            throw ApiFailure::unreachable($this->name, $e);
        }
    }

    /**
     * @throws ApiFailure
     */
    private function decode(string $body): mixed
    {
        if (str_starts_with($body, self::XSSI_PREFIX)) {
            $body = substr($body, strlen(self::XSSI_PREFIX));
        }
        try {
            return json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ApiFailure::unexpected($this->name, 'JSON', $body, $e);
        }
    }
}
