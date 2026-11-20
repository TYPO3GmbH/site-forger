<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Service;

use App\DTO\User;
use App\Elastic\ElasticUtility;
use App\Elastic\Users;
use Redmine\Client\Client;
use Redmine\Exception as RedmineException;
use Redmine\Http\HttpClient;
use Redmine\Http\HttpFactory;

/**
 * Fills the index "user" from Forge, see App\Elastic\Users, in the job "Update users" (App\Command\ImportUsersCommand):
 * the source of the users for now.
 * The importer writes the authors and assignees of the issues it writes, empty but for number and name
 * (Users::ensure()); this fills them:
 *
 * 1. takes the people of the issues already in the index over that the index "user" lacks, without a request:
 *    their number, their name, and their email, if the import kept it there before the index "user". So the index
 *    "user" gets complete again by itself, also when it was lost;
 * 2. asks Forge for some of the users it never asked for, those without an email first, and for those it asked
 *    longest ago, after REFRESH_AFTER_DAYS: one request each, Forge lists no users to anyone but its admins.
 *
 * A rate limit (429) or another error of Forge ends step 2 for the run, it does not wait: the next run goes on.
 * The issues are written without waiting for their users, the avatar shows once the email is known: Gravatar
 * finds it by the email, without one the pages show its placeholder.
 */
final readonly class ForgeUserSync
{
    /**
     * After this many days a user is asked for again: a new name or email.
     */
    public const int REFRESH_AFTER_DAYS = 30;

    /**
     * Users each Forge import asks for: some seconds, thousands of users in a day.
     */
    public const int PER_RUN = 30;

    /**
     * The step of the Forge import that asks for the users, as the command runs page shows it.
     */
    public const string STEP = 'Load the users from Forge';

    /**
     * The people of the issues, by the field of their number.
     */
    private const array PEOPLE_FIELDS = ['author', 'assigned_to'];
    private const int AGGREGATION_PAGE = 1000;

    public function __construct(
        private Client $forgeClient,
        private Users $users,
        private CommandRunRecorder $recorder,
    ) {
    }

    /**
     * Step 1: writes the people of the issues the index "user" lacks.
     *
     * @return int how many it wrote
     */
    public function takeOverFromIssues(): int
    {
        $missing = self::missing($this->peopleOfIssues(), $this->users->all());
        $this->users->write($missing);
        $this->recorder->document(count($missing));

        return count($missing);
    }

    /**
     * Step 2: asks Forge for at most $limit users that are due.
     *
     * @return array{updated: int, gone: int, stopped: string|null} why it stopped early, if it did
     */
    public function fetchDue(int $limit, \DateTimeImmutable $now): array
    {
        $due = self::due($this->users->all(), $now, $limit);
        $written = [];
        $replaced = [];
        $gone = 0;
        $stopped = null;
        try {
            foreach ($due as $position => $user) {
                $status = 0;
                try {
                    $answer = $this->fetch($user->forgeId, $status);
                } catch (RedmineException $e) {
                    // Forge could not be reached: the next run tries again
                    $stopped = 'Forge could not be reached: ' . $e->getMessage();
                    break;
                }
                if (null !== $answer) {
                    $written[] = $user->updatedBy(User::fromForge($answer, $now));
                    $replaced[] = $user->id();
                } elseif (in_array($status, [403, 404], true)) {
                    // Deleted or locked, or a group: Forge has no user for a group that is assigned
                    $written[] = $user->asGone($now);
                    ++$gone;
                } else {
                    $stopped = 429 === $status ? 'Forge rate limit' : sprintf('Forge responded with status %d', $status);
                    break;
                }
                $this->recorder->progress(self::STEP, $position + 1, count($due));
                // Stephan Großberndt asked for a pause between the requests to Forge, see ImportCommand::importIssues()
                usleep(50000);
            }
        } finally {
            $this->users->write($this->withGerrit($written), $replaced);
            $this->recorder->document(count($written));
        }

        return ['updated' => count($written) - $gone, 'gone' => $gone, 'stopped' => $stopped];
    }

    /**
     * The users with what the index knows of them from Gerrit: Forge told the username, a change may have written the
     * person under it before, with the number on Gerrit. Also with a login with the TYPO3 account since the run read
     * the users, see App\Elastic\Users::recordLogin().
     *
     * @param list<User> $users
     *
     * @return list<User>
     */
    private function withGerrit(array $users): array
    {
        $usernames = array_values(array_filter(array_map(static fn (User $user): ?string => $user->username, $users)));
        $existing = [] === $usernames ? [] : $this->users->byIds($usernames);

        return self::merged($users, $existing);
    }

    /**
     * @param list<User>          $users
     * @param array<string, User> $existing the documents by id
     *
     * @return list<User>
     */
    public static function merged(array $users, array $existing): array
    {
        return array_map(static function (User $user) use ($existing): User {
            $other = $existing[$user->id()] ?? null;
            $merged = null === $other ? $user : $user->mergedWith($other);

            // Equal unless the document has more: the number on Gerrit, an address, a login since the run began
            return $merged == $user ? $user : $merged;
        }, $users);
    }

    /**
     * The people of the issues that the index "user" does not have.
     *
     * @param array<int, User> $people of the issues, by Forge number
     * @param array<int, User> $known  of the index "user", by Forge number
     *
     * @return list<User>
     */
    public static function missing(array $people, array $known): array
    {
        return array_values(array_diff_key($people, $known));
    }

    /**
     * The users to ask Forge for next: never asked for, those without an email first, then those asked for longest
     * ago, after REFRESH_AFTER_DAYS.
     *
     * @param array<int, User> $users
     *
     * @return list<User>
     */
    public static function due(array $users, \DateTimeImmutable $now, int $limit): array
    {
        $refreshBefore = $now->modify(sprintf('-%d days', self::REFRESH_AFTER_DAYS));
        $due = array_filter($users, static fn (User $user): bool => null === $user->fetchedOn || $user->fetchedOn < $refreshBefore);
        usort($due, static fn (User $a, User $b): int => [null !== $a->fetchedOn, [] !== $a->emails, $a->fetchedOn, -$a->forgeId]
            <=> [null !== $b->fetchedOn, [] !== $b->emails, $b->fetchedOn, -$b->forgeId]);

        return array_slice($due, 0, max(0, $limit));
    }

    /**
     * The authors and assignees of the issues, with the email the import kept, if any.
     *
     * @return array<int, User> by Forge number
     */
    private function peopleOfIssues(): array
    {
        $people = [];
        $index = ElasticUtility::getIndexByName('issue');
        foreach (self::PEOPLE_FIELDS as $field) {
            $after = null;
            do {
                $composite = ['size' => self::AGGREGATION_PAGE, 'sources' => [['id' => ['terms' => ['field' => $field . '.id']]]]];
                if (null !== $after) {
                    $composite['after'] = $after;
                }
                $aggregations = $index->search([
                    'size' => 0,
                    'aggs' => ['people' => [
                        'composite' => $composite,
                        'aggs' => [
                            'latest' => ['top_hits' => ['size' => 1, '_source' => [$field . '.id', $field . '.name'], 'sort' => [['updated_on' => 'desc']]]],
                            'withEmail' => [
                                'filter' => ['exists' => ['field' => $field . '.email']],
                                'aggs' => ['latest' => ['top_hits' => ['size' => 1, '_source' => [$field . '.email'], 'sort' => [['updated_on' => 'desc']]]]],
                            ],
                        ],
                    ]],
                ])->getAggregations();
                foreach ($aggregations['people']['buckets'] ?? [] as $bucket) {
                    $person = $bucket['latest']['hits']['hits'][0]['_source'][$field] ?? [];
                    $person['email'] = $bucket['withEmail']['latest']['hits']['hits'][0]['_source'][$field]['email'] ?? null;
                    $user = User::fromIssue($person);
                    if (null === $user) {
                        continue;
                    }
                    // An email the other field knows stays
                    $people[$user->forgeId] = [] === $user->emails && isset($people[$user->forgeId]) ? $people[$user->forgeId] : $user;
                }
                $after = $aggregations['people']['after_key'] ?? null;
            } while (null !== $after && [] !== ($aggregations['people']['buckets'] ?? []));
        }

        return $people;
    }

    /**
     * The user as Forge answers, null if it does not, with the status in $status.
     *
     * @return array<string, mixed>|null
     */
    private function fetch(int $forgeId, int &$status): ?array
    {
        if (!$this->forgeClient instanceof HttpClient) {
            throw new \LogicException(sprintf('The client of Forge (%s) sends no requests of its own.', $this->forgeClient::class));
        }
        $response = $this->forgeClient->request(HttpFactory::makeJsonRequest('GET', sprintf('/users/%d.json', $forgeId)));
        $status = $response->getStatusCode();
        if ($status >= 400) {
            return null;
        }
        $answer = json_decode($response->getContent(), true);
        if (!is_array($answer) || !isset($answer['user']['id'])) {
            $status = 502;

            return null;
        }

        return $answer['user'];
    }
}
