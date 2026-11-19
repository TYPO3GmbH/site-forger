<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Header;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Reads the events of GitHub repositories as interactions.
 *
 * The delta (a page count is given) only asks GitHub what changed: the ETag of every events page is kept,
 * GitHub answers an unchanged page with 304 Not Modified, which costs nothing of the rate limit. The ETag
 * is stored once all events of a page were handed on, so a run that fails half way loads the page again.
 * The profiles of the actors (name, email) are kept for a day instead of being loaded on every run.
 */
class GithubService
{
    private const int USER_CACHE_LIFETIME = 86400;
    private const int ETAG_CACHE_LIFETIME = 604800;

    /** @var array<string, array<string, mixed>> */
    private array $usersCache = [];

    public function __construct(
        private readonly ClientInterface $client,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return \Generator<string[]>
     */
    public function getListOfRepositoryNamesForOrganization(string $organization): \Generator
    {
        $currentPage = 0;
        do {
            ++$currentPage;
            $response = $this->client->get('orgs/' . $organization . '/repos?per_page=100&page=' . $currentPage);
            $repoData = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            yield array_column($repoData, 'name');
        } while ($currentPage < $this->parseLinkHeader($response));
    }

    public function getLastPageOfRepositoryEvents(string $organization, string $repo): int
    {
        $headResponse = $this->client->head('repos/' . $organization . '/' . $repo . '/events?per_page=100');

        return $this->parseLinkHeader($headResponse);
    }

    public function getLastPageOfRepositoryIssueEvents(string $organization, string $repo): int
    {
        $headResponse = $this->client->head('repos/' . $organization . '/' . $repo . '/issues/events?per_page=100');

        return $this->parseLinkHeader($headResponse);
    }

    public function getInteractionsForRepository(string $organization, string $repo, ?int $pageCount): \Generator
    {
        $lastPage = $pageCount ?? $this->getLastPageOfRepositoryEvents($organization, $repo);
        for ($page = 1; $page <= $lastPage; ++$page) {
            $url = 'repos/' . $organization . '/' . $repo . '/events?per_page=100&page=' . $page;
            [$events, $etag] = $this->getEvents($url, null !== $pageCount);
            foreach ($events as $event) {
                $interaction = $this->mapCommonData($event);

                $interaction['interaction_source_id'] = $event['id'];
                $type = str_replace('event', '', strtolower((string) $event['type']));
                $action = strtolower($event['payload']['action'] ?? 'generic');
                $interaction['interaction_type'] = 'github:' . strtolower($organization) . ':' . $type . ':' . $action;
                yield $interaction;
            }
            $this->storeEtag($url, $etag);
        }
    }

    public function getInteractionsForIssuesInRepository(string $organization, string $repo, ?int $pageCount): \Generator
    {
        $lastPage = $pageCount ?? $this->getLastPageOfRepositoryIssueEvents($organization, $repo);
        for ($page = 1; $page <= $lastPage; ++$page) {
            $url = 'repos/' . $organization . '/' . $repo . '/issues/events?per_page=100&page=' . $page;
            [$events, $etag] = $this->getEvents($url, null !== $pageCount);
            foreach ($events as $event) {
                $interaction = $this->mapCommonData($event);

                $interaction['interaction_source_id'] = $event['issue']['id'];
                $type = (isset($event['commit_id'])) ? 'pr' : 'issue';
                $interaction['interaction_type'] = 'github:' . strtolower($organization) . ':' . $type . ':' . $event['event'];
                yield $interaction;
            }
            $this->storeEtag($url, $etag);
        }
    }

    /**
     * The events of a page, none if the page did not change since it was last read (conditional).
     *
     * @return array{0: list<array<string, mixed>>, 1: string|null} the events and the ETag to store once they are handled
     */
    private function getEvents(string $url, bool $conditional): array
    {
        $headers = [];
        $stored = $conditional ? $this->cache->getItem($this->etagKey($url)) : null;
        if (null !== $stored && $stored->isHit()) {
            $headers['If-None-Match'] = $stored->get();
        }
        $response = $this->client->request('GET', $url, ['headers' => $headers]);
        if (304 === $response->getStatusCode()) {
            return [[], null];
        }
        $etag = $response->getHeaderLine('ETag');

        return [
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
            $conditional && '' !== $etag ? $etag : null,
        ];
    }

    private function storeEtag(string $url, ?string $etag): void
    {
        if (null === $etag) {
            return;
        }
        $item = $this->cache->getItem($this->etagKey($url));
        $item->set($etag);
        $item->expiresAfter(self::ETAG_CACHE_LIFETIME);
        $this->cache->save($item);
    }

    private function etagKey(string $url): string
    {
        return 'github_etag_' . hash('xxh128', $url);
    }

    /**
     * @return array<string, mixed> the profile, empty if GitHub does not know the user (any more)
     */
    private function getUser(string $login, string $url): array
    {
        if (isset($this->usersCache[$login])) {
            return $this->usersCache[$login];
        }
        $item = $this->cache->getItem('github_user_' . hash('xxh128', $login));
        if (!$item->isHit()) {
            try {
                $response = $this->client->get($url);
                $userData = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            } catch (ClientException) {
                $userData = [];
            }
            $item->set(['name' => $userData['name'] ?? null, 'email' => $userData['email'] ?? null]);
            $item->expiresAfter(self::USER_CACHE_LIFETIME);
            $this->cache->save($item);
        }

        return $this->usersCache[$login] = $item->get();
    }

    private function mapCommonData(array $event): array
    {
        $user = $this->getUser($event['actor']['login'], $event['actor']['url']);
        $interaction = [];
        $interaction['fullname'] = $user['name'] ?? $event['actor']['login'];
        $interaction['email'] = $user['email'] ?? '';
        $interaction['interaction_id'] = $event['id'];
        $interaction['interaction_source'] = 'Github';
        $dateTime = new \DateTime($event['created_at']);
        if (!$dateTime) {
            throw new \InvalidArgumentException('Date format for "' . $event['created_at'] . '" could not be parsed');
        }
        $interaction['interacted_on'] = $dateTime->format('Y/m/d H:i:s');
        $interaction['account'] = $event['actor']['login'];

        return $interaction;
    }

    private function parseLinkHeader(ResponseInterface $headResponse): int
    {
        $linkHeader = $headResponse->getHeader('Link');
        if (empty($linkHeader)) {
            return 1;
        }
        $parsed = Header::parse($linkHeader);
        $last = array_first(array_filter(
            $parsed,
            static fn (array $set): bool => 'last' === $set['rel']
        ))[0] ?? '&page=1';
        preg_match('#&page=(\d+)#', (string) $last, $matches);

        return (int) $matches[1];
    }
}
