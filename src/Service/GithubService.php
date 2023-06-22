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
use Psr\Http\Message\ResponseInterface;

class GithubService
{
    private array $usersCache = [];

    public function __construct(
        private readonly ClientInterface $client
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
            $response = $this->client->get('repos/' . $organization . '/' . $repo . '/events?per_page=100&page=' . $page);
            $events = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            foreach ($events as $event) {
                $interaction = $this->mapCommonData($event);

                $interaction['interaction_source_id'] = $event['id'];
                $type = str_replace('event', '', strtolower((string) $event['type']));
                $action = strtolower($event['payload']['action'] ?? 'generic');
                $interaction['interaction_type'] = 'github:' . strtolower($organization) . ':' . $type . ':' . $action;
                yield $interaction;
            }
        }
    }

    public function getInteractionsForIssuesInRepository(string $organization, string $repo, ?int $pageCount): \Generator
    {
        $lastPage = $pageCount ?? $this->getLastPageOfRepositoryIssueEvents($organization, $repo);
        for ($page = 1; $page <= $lastPage; ++$page) {
            $response = $this->client->get('repos/' . $organization . '/' . $repo . '/issues/events?per_page=100&page=' . $page);
            $events = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            foreach ($events as $event) {
                $interaction = $this->mapCommonData($event);

                $interaction['interaction_source_id'] = $event['issue']['id'];
                $type = (isset($event['commit_id'])) ? 'pr' : 'issue';
                $interaction['interaction_type'] = 'github:' . strtolower($organization) . ':' . $type . ':' . $event['event'];
                yield $interaction;
            }
            ++$page;
        }
    }

    private function mapCommonData(array $event): array
    {
        if (!isset($this->usersCache[$event['actor']['login']])) {
            try {
                $response = $this->client->get($event['actor']['url']);
                $userData = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            } catch (ClientException) {
                $userData = [];
            }
            $this->usersCache[$event['actor']['login']] = $userData;
        }
        $interaction = [];
        $interaction['fullname'] = $this->usersCache[$event['actor']['login']]['name'] ?? $event['actor']['login'];
        $interaction['email'] = $this->usersCache[$event['actor']['login']]['email'] ?? '';
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
        $last = array_values(
            array_filter(
                $parsed,
                static fn (array $set): bool => 'last' === $set['rel']
            )
        )[0][0] ?? '&page=1';
        preg_match('#&page=(\d+)#', (string) $last, $matches);

        return (int) $matches[1];
    }
}
