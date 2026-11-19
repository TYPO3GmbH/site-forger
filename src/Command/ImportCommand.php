<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Elastic\ElasticUtility;
use App\Elastic\ImportWatermark;
use App\Elastic\IndexSetup;
use App\Elastic\ProjectIssues;
use App\Elastic\ReviewSubject;
use App\Enum\IssueStatusEnum;
use App\Service\CommandRunRecorder;
use App\Service\GithubService;
use Elastica\Document;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Redmine\Client\Client;
use Redmine\Exception\UnexpectedResponseException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsCommand(name: 'app:import', description: 'Imports data from Redmine (forge.typo3.org), Gerrit (review.typo3.org) and Github')]
class ImportCommand extends Command implements LoggerAwareInterface
{
    use LockableTrait;
    use LoggerAwareTrait;
    protected const BAMBOO_USER_ID = [49454, 51735, 52386];
    /**
     * At most this many issues or changes per delta run. The rest follows in the next run (Forge),
     * or with the weekly reviewFull (Gerrit, which only lists the newest changes first).
     */
    private const int DELTA_LIMIT_FORGE = 2000;
    private const int DELTA_LIMIT_GERRIT = 3000;
    private const int PAGE_SIZE = 100;
    /**
     * Retries of a rate-limited request to Forge, see callRedmine().
     */
    private const int FORGE_RETRIES = 6;
    /**
     * Changes per request when loading the details of the changed ones.
     */
    private const int GERRIT_DETAIL_BATCH = 25;
    private const string GERRIT_PROJECT = 'project:Packages/TYPO3.CMS';
    private const array GERRIT_DETAIL_OPTIONS = ['DETAILED_LABELS', 'ALL_REVISIONS', 'ALL_FILES', 'CURRENT_COMMIT', 'MESSAGES', 'DETAILED_ACCOUNTS'];
    /**
     * Seconds the email of a Forge user is kept, so a run does not ask Forge for authors and assignees it knows.
     */
    private const int USER_CACHE_LIFETIME = 86400;
    protected static array $importModes = ['forgeFull', 'forgeDelta', 'reviewFull', 'reviewDelta', 'github', 'githubDelta'];
    private array $userCache = [];
    protected OutputInterface $output;

    public function __construct(
        private readonly ParameterBagInterface $parameters,
        private readonly Client $forgeClient,
        private readonly GithubService $githubService,
        private readonly ClientInterface $client,
        private readonly IndexSetup $indexSetup,
        private readonly CommandRunRecorder $recorder,
        private readonly ImportWatermark $watermark,
        private readonly CacheInterface $cache,
        private readonly ProjectIssues $projectIssues,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'mode',
                null,
                InputOption::VALUE_REQUIRED,
                'Import Mode: Allowed values are \'forgeFull\', \'forgeDelta\', \'reviewFull\', \'reviewDelta\', \'github\', \'githubDelta\''
            )
            ->addOption(
                'startAt',
                null,
                InputOption::VALUE_OPTIONAL,
                'at which line should the script start',
                0
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                '(mode=forgeFull, reviewFull only) Import every issue or change, also those the index has in their current state, e.g. after a change of what is imported'
            )
            ->addOption(
                'organization',
                null,
                InputOption::VALUE_OPTIONAL,
                '(mode=github only) Which Github organization to import - defaults to TYPO3-Documentation',
                'TYPO3-Documentation'
            )
            ->addOption(
                'repository',
                null,
                InputOption::VALUE_OPTIONAL,
                '(mode=github only) Which Github repository to import - if not given complete organization will be imported'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->output = $output;
        $mode = $input->getOption('mode');
        $startAt = (int) $input->getOption('startAt');
        $force = (bool) $input->getOption('force');
        if (!in_array($mode, self::$importModes, true)) {
            $io->error('Please select one of the modes: "' . implode('", "', self::$importModes) . '"');

            return Command::INVALID;
        }

        // Health check before writing: a missing index would be created by Elasticsearch with a dynamic mapping
        foreach ($this->indexSetup->ensureAll() as $name => $result) {
            if (IndexSetup::UNCHANGED !== $result['action']) {
                $io->note(sprintf('Elasticsearch index "%s" %s (%d documents)', $name, $result['action'], $result['documents']));
            }
        }

        try {
            switch ($mode) {
                case 'forgeFull':
                    if ($this->lock('forge')) {
                        try {
                            $this->removeIssuesOfOtherProjects();
                            $this->importFromForge($startAt, $force);
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another forge import');
                        $this->recorder->locked();

                        return Command::SUCCESS;
                    }
                    break;
                case 'forgeDelta':
                    if ($this->lock('forge')) {
                        try {
                            $this->removeIssuesOfOtherProjects();
                            $this->importForgeDelta();
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another forge import');
                        $this->recorder->locked();

                        return Command::SUCCESS;
                    }

                    break;
                case 'reviewDelta':
                    if ($this->lock('gerrit')) {
                        try {
                            $this->importReviewDelta($output);
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another gerrit import');
                        $this->recorder->locked();

                        return Command::SUCCESS;
                    }
                    break;
                case 'reviewFull':
                    if ($this->lock('gerrit')) {
                        try {
                            $this->importReviews($output, null, $force, $startAt);
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another gerrit import');
                        $this->recorder->locked();

                        return Command::SUCCESS;
                    }
                    break;
                case 'github':
                case 'githubDelta':
                    $organization = (string) $input->getOption('organization');
                    $repo = $input->getOption('repository');
                    // One lock per organization (and repository): cron starts the organizations at the same minute
                    $lockName = 'github:' . strtolower($organization) . (null === $repo ? '' : '/' . strtolower((string) $repo));
                    if ($this->lock($lockName)) {
                        try {
                            $this->importFromGithub($organization, $repo, $io, 'githubDelta' === $mode ? 1 : null);
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln(sprintf('Locked by another github import of %s', $organization . (null === $repo ? '' : '/' . $repo)));
                        $this->recorder->locked();
                    }

                    return Command::SUCCESS;
            }
        } catch (\Exception $exception) {
            // If any exception occurs, release the lock, else the process will run never again.
            $this->logger->error($exception->getMessage());
            $this->recorder->fail($exception::class . ': ' . $exception->getMessage());
            $output->writeln('Release lock because of exception: ' . $exception->getMessage());
            $this->release();

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Goes through every issue, newest first. Only the issues the index does not have in their current state
     * are imported (journals, user, write), unless forced: the full import is a safety net for what the deltas
     * missed and costs little more than listing the issues.
     *
     * After a complete listing the issues the index has but Forge did not list are checked one by one, see
     * removeVanishedIssues().
     */
    private function importFromForge(int $startOffset, bool $force): void
    {
        $offset = max(0, $startOffset);
        $page = $this->getAllForgeIssuesPaginated(self::PAGE_SIZE, $offset);
        $totalCount = (int) ($page['total_count'] ?? 0);
        $progressBar = new ProgressBar($this->output, max(0, $totalCount - $offset));
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        $skipped = 0;
        $listed = [];
        while ($offset < $totalCount) {
            if ($offset > max(0, $startOffset)) {
                $page = $this->getAllForgeIssuesPaginated(self::PAGE_SIZE, $offset);
            }
            // break a soon as the API returns no more issues
            if ([] === ($page['issues'] ?? [])) {
                break;
            }
            foreach ($page['issues'] as $doc) {
                $listed[(string) $doc['id']] = true;
            }
            $skipped += $this->importIssues($page['issues'], 'Forge issues', $progressBar, !$force);
            $offset += self::PAGE_SIZE;
        }
        $progressBar->finish();
        $this->output->writeln('');
        $this->output->writeln(sprintf('%d issues up to date in the index, skipped', $skipped));

        if (0 === $startOffset && $offset >= $totalCount) {
            $this->removeVanishedIssues($listed);
        }
    }

    /**
     * Issues the index has but Forge did not list: deleted on Forge, moved to another project, or only missed,
     * as the order of the list shifts while the import runs for hours. Each of them is asked for on its own:
     * gone or in another project, it is removed from the index, otherwise imported again.
     *
     * @param array<string, true> $listed the ids Forge listed
     */
    private function removeVanishedIssues(array $listed): void
    {
        $vanished = [];
        foreach ($this->projectIssues->indexedIds() as $id) {
            if (!isset($listed[$id])) {
                $vanished[] = $id;
            }
        }
        $this->output->writeln(sprintf('%d issues in the index that Forge did not list, checking them one by one', count($vanished)));

        $remove = [];
        foreach ($vanished as $position => $id) {
            $this->recorder->progress('Checking issues Forge did not list', $position + 1, count($vanished));
            try {
                $issue = $this->callRedmine(fn () => $this->forgeClient->getApi('issue')->show((int) $id));
            } catch (\RuntimeException $e) {
                if (in_array($e->getCode(), [403, 404], true)) {
                    // Deleted on Forge
                    $remove[] = $id;
                    continue;
                }
                $this->recorder->warning(sprintf('Forge issue #%s could not be checked: %s', $id, $e->getMessage()));
                continue;
            } finally {
                usleep(50000);
                $this->refreshLock();
            }
            if (is_array($issue) && isset($issue['issue'])) {
                // importIssues() removes it if it is in another project, and imports it otherwise
                $this->importIssues([$issue['issue']], 'Checking issues Forge did not list', null, false);
            }
        }
        $removed = $this->projectIssues->remove($remove);
        $this->output->writeln(sprintf('Removed %d issues that are gone from Forge', $removed));
    }

    /**
     * Imports the issues changed since the latest change in the index, oldest first: a run that ends early
     * (limit, error) leaves the index at a consistent point, and the next run goes on from there.
     */
    private function importForgeDelta(): void
    {
        $since = $this->watermark->since('issue');
        if (null === $since) {
            // An empty index needs forgeFull, until then the latest issues at least
            $this->recorder->warning('The issue index is empty, run --mode=forgeFull. Imported the latest issues only.');
            $this->importIssues($this->getAllForgeIssuesPaginated(self::PAGE_SIZE, 0)['issues'] ?? [], 'Latest Forge issues', null, false);

            return;
        }

        $filter = ImportWatermark::redmineFilter($since);
        $first = $this->getAllForgeIssuesPaginated(self::PAGE_SIZE, 0, $filter);
        $total = (int) ($first['total_count'] ?? 0);
        $limit = min($total, self::DELTA_LIMIT_FORGE);
        $this->output->writeln(sprintf('%d issues changed since %s, importing %d', $total, $since->format('Y-m-d H:i:s'), $limit));
        if ($total > self::DELTA_LIMIT_FORGE) {
            $this->recorder->warning(sprintf('%d issues changed since %s, imported the oldest %d, the next run goes on', $total, $since->format('Y-m-d H:i:s'), self::DELTA_LIMIT_FORGE));
        }

        $progressBar = new ProgressBar($this->output, $limit);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $page = $first;
        $unchanged = 0;
        for ($offset = 0; $offset < $limit; $offset += self::PAGE_SIZE) {
            if ($offset > 0) {
                $page = $this->getAllForgeIssuesPaginated(self::PAGE_SIZE, $offset, $filter);
            }
            if ([] === ($page['issues'] ?? [])) {
                break;
            }
            $unchanged += $this->importIssues(array_slice($page['issues'], 0, $limit - $offset), 'Changed Forge issues', $progressBar, true);
        }
        $progressBar->finish();
        $this->output->writeln('');
        $this->output->writeln(sprintf('%d of them unchanged since the last import, skipped', $unchanged));
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @param bool                       $skipUnchanged skip the issues the index has with the same "updated_on"
     *
     * @return int the number of skipped issues
     */
    private function importIssues(array $issues, string $phase, ?ProgressBar $progressBar, bool $skipUnchanged): int
    {
        // Issues of other projects (subprojects) are not imported, and removed if the index has them from before
        $otherProjects = [];
        foreach ($issues as $position => $doc) {
            if (!$this->projectIssues->belongsToProject($doc)) {
                $otherProjects[] = $doc['id'];
                unset($issues[$position]);
                if (null !== $progressBar) {
                    $progressBar->advance();
                }
            }
        }
        if ([] !== $otherProjects && ($removed = $this->projectIssues->remove($otherProjects)) > 0) {
            $this->output->writeln(sprintf('Removed %d issues of other projects from the index', $removed));
        }

        $changed = null;
        if ($skipUnchanged) {
            $updated = [];
            foreach ($issues as $doc) {
                $updated[(string) $doc['id']] = $this->fixDateFormat((string) $doc['updated_on'], 'Redmine');
            }
            $changed = array_flip(ImportWatermark::changed($updated, $this->watermark->storedUpdates('issue', array_keys($updated))));
        }
        $skipped = 0;
        foreach ($issues as $doc) {
            if (null !== $changed && !isset($changed[(string) $doc['id']])) {
                ++$skipped;
                if (null !== $progressBar) {
                    $progressBar->advance();
                    $this->reportProgress($phase, $progressBar);
                }
                continue;
            }
            try {
                $this->getTicketAndJournal($doc);
                $this->refreshLock();
            } catch (\Exception $e) {
                // The import goes on with the next issue
                $this->recorder->warning(sprintf('Forge issue #%s: %s', $doc['id'] ?? '?', $e->getMessage()));
            } finally {
                // Stephan Großberndt asked for a sleep of between 50 - 100ms between requests to Forge on April 15th, 2024 in Slack channel #t3a-t3g-infra
                // Since we run into a lot of requests here, 50ms should be fine to not block the overall import process for too long
                usleep(50000);
            }
            if (null !== $progressBar) {
                $progressBar->advance();
                $this->reportProgress($phase, $progressBar);
            }
        }

        return $skipped;
    }

    /**
     * Imports the Gerrit changes updated since the latest change in the index, see importReviews().
     */
    private function importReviewDelta(OutputInterface $output): void
    {
        $since = $this->watermark->since('review');
        if (null === $since) {
            $this->recorder->warning('The review index is empty, run --mode=reviewFull. Imported the latest changes only.');
            $progressBar = new ProgressBar($output->section());
            foreach ($this->getReviewBlock(0, self::PAGE_SIZE, new ProgressBar($output->section()), $progressBar) as $review) {
                $this->addDocumentToElastic($review, 'review');
            }

            return;
        }
        $this->importReviews($output, $since, false);
    }

    /**
     * Imports the Gerrit changes in two steps: a light query lists the changes with their update time (all of
     * them, or those updated since), only those the index does not have in this state are loaded with their
     * revisions, files and messages, unless forced.
     *
     * Gerrit lists the newest changes first. The delta writes the changes once all of them are loaded, so a run
     * that fails half way does not move the latest change in the index, and the next run tries again. The full
     * import writes them batch by batch, it handles far more changes than fit in memory.
     * The interactions are written on the way, writing them twice does no harm.
     */
    private function importReviews(OutputInterface $output, ?\DateTimeImmutable $since, bool $force, int $startAt = 0): void
    {
        $delta = null !== $since;
        $mainSection = $output->section();
        $subSection = $output->section();
        $progressBar = new ProgressBar($mainSection);
        $subBar = new ProgressBar($subSection);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        // Step 1: which changes are not up to date in the index, without their details
        $query = self::GERRIT_PROJECT . ($delta ? ' ' . ImportWatermark::gerritOperator($since) : '');
        $listed = 0;
        $changed = [];
        $more = true;
        for ($start = max(0, $startAt); $more && (!$delta || $start < self::DELTA_LIMIT_GERRIT); $start += self::PAGE_SIZE) {
            $changes = $this->decodeGerrit($this->getCommitsFromGerrit(self::PAGE_SIZE, $start, $query, []));
            $updated = [];
            foreach ($changes as $change) {
                $updated[(string) $change['_number']] = $this->fixDateFormat($change['updated'], 'Gerrit');
            }
            $listed += count($updated);
            array_push($changed, ...($force ? array_map('strval', array_keys($updated)) : ImportWatermark::changed($updated, $this->watermark->storedUpdates('review', array_keys($updated)))));
            $more = [] !== $changes && array_key_exists('_more_changes', array_last($changes));
            $this->recorder->progress('Listing Gerrit changes', $listed);
            $this->refreshLock();
        }
        if ($delta && $more) {
            $this->recorder->warning(sprintf('More than %d changes were updated since %s, older ones are missing until the next reviewFull', self::DELTA_LIMIT_GERRIT, $since->format('Y-m-d H:i:s')));
        }
        $output->writeln(sprintf(
            '%d changes %s, %d of them not up to date in the index',
            $listed,
            $delta ? 'updated since ' . $since->format('Y-m-d H:i:s') : 'in Gerrit',
            count($changed),
        ));

        // Step 2: the details of the changes that are not up to date
        $reviews = [];
        $progressBar->setMaxSteps(count($changed));
        foreach (array_chunk($changed, self::GERRIT_DETAIL_BATCH) as $batch) {
            $batchQuery = implode(' OR ', array_map(static fn (string $number): string => 'change:' . $number, $batch));
            $block = $this->getReviewBlock(0, count($batch), $subBar, $progressBar, $batchQuery);
            if ($delta) {
                $reviews += $block;
                continue;
            }
            foreach ($block as $review) {
                $this->addDocumentToElastic($review, 'review');
            }
        }

        foreach ($reviews as $review) {
            $this->addDocumentToElastic($review, 'review');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeGerrit(ResponseInterface $response): array
    {
        return json_decode(str_replace(")]}'", '', (string) $response->getBody()), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Imports a single ticket.
     * A sleep(1) might be useful here to not hammer forge.
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    private function getTicketAndJournal(array $issue): void
    {
        // import journals only if issue status is "Under review"
        if (isset($issue['status']['id']) && IssueStatusEnum::UNDER_REVIEW === IssueStatusEnum::tryFrom((int) $issue['status']['id'])) {
            $issueWithJournals = $this->callRedmine(fn () => $this->forgeClient->getApi('issue')->show($issue['id'], ['include' => ['journals']]));
            if (!is_array($issueWithJournals) || !isset($issueWithJournals['issue'])) {
                // Forge answered without the issue, it keeps its previous state in the index
                throw new \RuntimeException(sprintf('Loading the journals failed, Forge answered: %s', mb_substr(is_string($issueWithJournals) ? trim($issueWithJournals) : get_debug_type($issueWithJournals), 0, 200)));
            }
            $this->addDocumentToElastic($this->injectUserData($this->projectIssues->withoutPrivateNotes($issueWithJournals['issue'])));
        } else {
            $this->addDocumentToElastic($this->injectUserData($issue));
        }
    }

    /**
     * Removes the issues of other projects the index has from before, see ProjectIssues.
     */
    private function removeIssuesOfOtherProjects(): void
    {
        $removed = $this->projectIssues->removeOtherProjects();
        if ($removed > 0) {
            $this->output->writeln(sprintf('Removed %d issues of other projects from the index', $removed));
        }
    }

    protected function importFromGithub(string $organization, ?string $repository, SymfonyStyle $io, ?int $pageCount = null): void
    {
        if (null === $repository) {
            $pageOfNames = $this->githubService->getListOfRepositoryNamesForOrganization($organization);
            foreach ($pageOfNames as $repositories) {
                foreach ($repositories as $repo) {
                    // we don't want to import the core repo again (and no forks in other orgs either)
                    if ('TYPO3.CMS' === $repo) {
                        continue;
                    }
                    $this->importRepoFromGithub($io, $organization, $repo, $pageCount);
                }
            }
        } else {
            $this->importRepoFromGithub($io, $organization, $repository, $pageCount);
        }
    }

    /**
     * @throws \JsonException
     */
    protected function importRepoFromGithub(SymfonyStyle $io, string $organization, string $repo, ?int $pageCount): void
    {
        $io->title('Importing data from ' . $organization . ' - Repository: ' . $repo);
        $io->writeln('');
        $lastPageOfRepositoryEvents = $pageCount ?? $this->githubService->getLastPageOfRepositoryEvents($organization, $repo);
        $lastPageOfRepositoryIssueEvents = $pageCount ?? $this->githubService->getLastPageOfRepositoryIssueEvents($organization, $repo);
        $progressBar = new ProgressBar($io, $lastPageOfRepositoryEvents * 100 + $lastPageOfRepositoryIssueEvents * 100);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        try {
            foreach ($this->githubService->getInteractionsForRepository($organization, $repo, $pageCount) as $interaction) {
                $this->addDocumentToElastic($interaction, 'interactions');
                $progressBar->advance();
                $this->reportProgress('GitHub ' . $organization . '/' . $repo, $progressBar);
            }
            foreach ($this->githubService->getInteractionsForIssuesInRepository($organization, $repo, $pageCount) as $interaction) {
                $this->addDocumentToElastic($interaction, 'interactions');
                $progressBar->advance();
                $this->reportProgress('GitHub ' . $organization . '/' . $repo, $progressBar);
            }
        } catch (ClientException $e) {
            // Without valid credentials or with the rate limit used up no repository can be read: the run fails
            if (in_array($e->getResponse()->getStatusCode(), [401, 403], true)) {
                throw new \RuntimeException(sprintf('GitHub responded with %d for %s/%s: %s', $e->getResponse()->getStatusCode(), $organization, $repo, $e->getMessage()), 0, $e);
            }
            $io->error($e->getMessage());
            $this->recorder->warning(sprintf('GitHub %s/%s: %s', $organization, $repo, $e->getMessage()));
        } catch (\Exception $e) {
            // GitHub respond sometimes with 500 errors, so we have to catch them and continue
            // A very wired case is that page 3 and 4 throw 500 errors, but page 5 and higher work again.
            // It looks like the GitHub API is not stable and have a bug 😳
            $io->error($e->getMessage());
            $this->recorder->warning(sprintf('GitHub %s/%s: %s', $organization, $repo, $e->getMessage()));
        }
        $progressBar->finish();
        $io->newLine(1);
    }

    /**
     * Adds the email of the author and of the assignee, for their avatars.
     */
    private function injectUserData(array $issue): array
    {
        // The author could be empty, so we have to check for that
        $issue['author']['email'] = $this->forgeUserEmail($issue['author']['id'] ?? null);
        if (isset($issue['assigned_to']['id'])) {
            // A group can be assigned, too: Forge has no user for it, so it gets no email
            $issue['assigned_to']['email'] = $this->forgeUserEmail($issue['assigned_to']['id']);
        }

        return $issue;
    }

    /**
     * The email of a Forge user, empty if Forge does not know it.
     */
    private function forgeUserEmail(int|string|null $userId): string
    {
        if (null === $userId) {
            return '';
        }
        if (!isset($this->userCache[$userId])) {
            // A rate limit (429) is thrown and not cached, the next run asks again
            $this->userCache[$userId] = $this->cache->get('forge_user_mail_' . (int) $userId, function (ItemInterface $item) use ($userId): string {
                $item->expiresAfter(self::USER_CACHE_LIFETIME);
                try {
                    $userRecord = $this->callRedmine(fn () => $this->forgeClient->getApi('user')->show($userId));
                } catch (\RuntimeException $e) {
                    // E.g. a deleted or locked user (404, 403): the issue is imported without the email
                    if (429 === $e->getCode()) {
                        throw $e;
                    }
                    $userRecord = null;
                }

                return is_array($userRecord) ? ($userRecord['user']['mail'] ?? '') : '';
            });
        }

        return $this->userCache[$userId];
    }

    /**
     * @throws \Exception
     */
    protected function getReviewBlock(int $current, int $perRun, ProgressBar $subBar, ProgressBar $mainProgressbar, string $query = self::GERRIT_PROJECT): array
    {
        $out = [];
        // Errors end the import: an empty result would silently skip the changes of this block
        $data = $this->decodeGerrit($this->getCommitsFromGerrit($perRun, $current, $query));
        foreach ($data as $change) {
            $mainProgressbar->advance();
            $this->reportProgress('Gerrit changes', $mainProgressbar);
            $labels = $this->extractLabels($change['labels']);
            if (!array_key_exists('mergeable', $change)) {
                $change['mergeable'] = false;
            }

            if (!array_key_exists('current_revision', $change)) {
                continue;
            }

            $patchSetNumber = 0;
            $interaction = [];
            $subBar->start(is_countable($change['messages']) ? count($change['messages']) : 0);
            foreach ($change['messages'] as $message) {
                $interaction['fullname'] = '';
                $interaction['email'] = '';
                try {
                    $interaction['interaction_id'] = $message['id'];
                    $messageId = $message['id'];
                    $interaction['interaction_source'] = 'Gerrit';
                    $interaction['interaction_source_id'] = $change['_number'];
                    $interaction['interacted_on'] = $this->fixDateFormat($message['date'], 'Gerrit');

                    $message['_revision_number'] ??= $patchSetNumber;

                    // Check if is merge-commit
                    if ($this->checkIfMessageIsMerge($message['message'])) {
                        $revisions = $change['revisions'];
                        usort($revisions, function ($a, $b) {
                            $a = new \DateTime($this->fixDateFormat($a['created'], 'Gerrit'));
                            $b = new \DateTime($this->fixDateFormat($b['created'], 'Gerrit'));

                            return $a->getTimestamp() <=> $b->getTimestamp();
                        });

                        $mergeRevision = array_last($revisions);
                        $this->addDocumentToElastic($this->buildRevisionArray($mergeRevision, 'core:review:merge', $messageId) + $interaction, 'interactions');

                        // If there is more than one revision, we want to give credit to both the original author
                        // and the person that patched this change with improvements (in case that is a different person)
                        if ((is_countable($revisions) ? count($revisions) : 0) > 1) {
                            $firstRevision = array_first($revisions);
                            // Only applicable if the first and merge revision was done by different people
                            if ($firstRevision['uploader']['_account_id'] !== $mergeRevision['uploader']['_account_id']) {
                                $this->addDocumentToElastic($this->buildRevisionArray($firstRevision, 'core:review:merge-author', $messageId) + $interaction, 'interactions');
                            }

                            $lastRevisionBeforeMerge = $revisions[array_key_last($revisions) - 1];
                            // Only applicable if the first and last and merge revision was done by different people
                            if (
                                $lastRevisionBeforeMerge['uploader']['_account_id'] !== $mergeRevision['uploader']['_account_id']
                                && $lastRevisionBeforeMerge['uploader']['_account_id'] !== $firstRevision['uploader']['_account_id']
                            ) {
                                $this->addDocumentToElastic($this->buildRevisionArray($lastRevisionBeforeMerge, 'core:review:merge-lastpatchset', $messageId) + $interaction, 'interactions');
                            }
                        }
                    } elseif ($this->checkIfMessageIsBackport($message['message'])) {
                        // code intentionally left blank
                    } else {
                        $interaction['account'] = $message['author']['username'];
                        $interaction['fullname'] = $message['author']['name'];
                        if (isset($message['author']['email'])) {
                            $interaction['email'] = $message['author']['email'];
                        }
                        // Is patchset, add as event
                        if ($patchSetNumber < $message['_revision_number']) {
                            $patchSetNumber = $message['_revision_number'];
                            $interaction['interaction_type'] = 'core:review:patchset';
                            $this->addDocumentToElastic($interaction, 'interactions');
                        }
                        // Check if message is "just" a new patchset
                        if ($this->checkIfMessageIsComment($message['message'])) {
                            $interaction['interaction_type'] = 'core:review:comment';
                            $this->addDocumentToElastic($interaction, 'interactions');
                        }
                        // Check if message is a code review
                        if ($this->checkIfMessageIsCodeReview($message['message'])) {
                            $interaction['interaction_type'] = 'core:review:codereview';
                            $this->addDocumentToElastic($interaction, 'interactions');
                        }
                        // Check if message is a verification
                        if ($this->checkIfMessageIsVerification($message['message'])) {
                            $interaction['interaction_type'] = 'core:review:testing';
                            $this->addDocumentToElastic($interaction, 'interactions');
                        }
                        // Check if message is abandoning
                        if ($this->checkIfMessageIsAbandoning($message['message'])) {
                            $interaction['interaction_type'] = 'core:review:abandon';
                            $this->addDocumentToElastic($interaction, 'interactions');
                        }
                    }
                } catch (\Exception $e) {
                    if (
                        !str_contains((string) $message['message'], 'Change could not be merged due to a path conflict')
                        && !str_contains((string) $message['message'], 'The change could not be merged due to a path conflict')
                        && !str_contains((string) $message['message'], 'Your change could not be merged due to a path conflict')
                        && !str_contains((string) $message['message'], 'Change has been successfully pushed into branch sandbox')
                        && !str_contains((string) $message['message'], 'Change could not be merged because of a missing dependency')
                    ) {
                        throw new \RuntimeException($e->getMessage());
                    }
                }
                $subBar->advance();
                $this->refreshLock();
            }

            $author = $change['revisions'][$change['current_revision']]['commit']['author']['name'];
            $authorEmail = $change['revisions'][$change['current_revision']]['commit']['author']['email'];
            $filesInPatchset = array_keys($change['revisions'][$change['current_revision']]['files']);
            if (11918 === (int) $change['_number']) {
                $change['revisions'][$change['current_revision']]['commit']['message'] = '6.0';
            }

            $reviewTags = ReviewSubject::tags($change['subject']);

            $out[$change['_number']] = [
                'id' => $change['_number'],
                'change_id' => $change['change_id'],
                'reviewstatus' => $change['status'],
                'subject' => $change['subject'],
                'branch' => 'master' === $change['branch'] ? 'main' : $change['branch'],
                'topic' => array_key_exists('topic', $change) ? $change['topic'] : 'no topic',
                'mergeable' => (true === $change['mergeable']) ? 'yes' : 'no',
                'affected_files' => is_countable($change['revisions'][$change['current_revision']]['files']) ? count($change['revisions'][$change['current_revision']]['files']) : 0,
                'insertions' => $change['insertions'],
                'deletions' => $change['deletions'],
                'reviewtags' => $reviewTags,
                'positive_reviews' => $labels['positive_reviews'],
                'negative_reviews' => $labels['negative_reviews'],
                'positive_verifications' => $labels['positive_verifications'],
                'negative_verifications' => $labels['negative_verifications'],
                'patchsets' => $change['revisions'][$change['current_revision']]['_number'],
                'created_on' => $this->fixDateFormat($change['created'], 'Gerrit'),
                'updated_on' => $this->fixDateFormat($change['updated'], 'Gerrit'),
                'releases' => $this->extractReleases(
                    $change['revisions'][$change['current_revision']]['commit']['message']
                ),
                'owner' => $author ?? 'Unknown',
                'ownerEmail' => $authorEmail ?? 'Unknown',
                'files' => $filesInPatchset,
                'work_in_progress' => ReviewSubject::isWorkInProgress($reviewTags) || ($change['work_in_progress'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * @throws \Exception
     */
    protected function extractReleases(string $message): array
    {
        // Fix fucked up lines in message
        $searches = ['Release: ',  '7,6'];
        $replaces = ['Releases: ', '7.6'];
        $message = str_replace($searches, $replaces, $message);
        $pattern = '/Releases:\s*(.*)/';
        preg_match($pattern, $message, $matches);
        if (!isset($matches[1])) {
            return ['omitted'];
        }

        $search = ['master.', 'master', '4.7.', '4.5 LTS'];
        $replaces = ['master,', 'main', '4.7,', '4.5'];
        $matches[1] = str_replace($search, $replaces, $matches[1]);
        $releaseArray = array_map(trim(...), explode(',', $matches[1]));
        foreach ($releaseArray as $key => $release) {
            if (str_contains((string) $release, ' ')) {
                throw new \InvalidArgumentException('Release contains space? ' . $message);
            }
            $releaseArray[$key] = strtolower((string) $release);
        }

        return $releaseArray;
    }

    /**
     * @throws \Exception
     */
    protected function extractLabels(array $labels): array
    {
        $out = [
            'positive_reviews' => 0,
            'negative_reviews' => 0,
            'positive_verifications' => 0,
            'negative_verifications' => 0,

        ];
        foreach ($labels as $labelType => $subRow) {
            $key = match ($labelType) {
                'Verified' => '_verifications',
                'Code-Review' => '_reviews',
                default => throw new \InvalidArgumentException('Unknown Label type ' . $labelType),
            };
            if (array_key_exists('all', $subRow)) {
                foreach ($subRow['all'] as $vote) {
                    if (isset($vote['value']) && !in_array($vote['_account_id'], self::BAMBOO_USER_ID, true)) {
                        switch ($vote['value']) {
                            case 1:
                                ++$out['positive' . $key];
                                break;
                            case 2:
                                $out['positive' . $key] += 2;
                                break;
                            case -1:
                                ++$out['negative' . $key];
                                break;
                            case -2:
                                $out['negative' . $key] += 2;
                                break;
                            case 0:
                                // nothing to do here
                                break;
                            default:
                                throw new \InvalidArgumentException('Unknown Vote Type');
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @throws \Exception
     */
    private function addDocumentToElastic(array $document, string $type = 'issue'): void
    {
        $elasticType = null;
        switch ($type) {
            case 'issue':
                if (isset($document['custom_fields'])) {
                    foreach ($document['custom_fields'] as $customField) {
                        switch ($customField['id']) {
                            case 3:
                                if (!empty($customField['value'])) {
                                    $document['tags'] = array_map(trim(...), explode(',', (string) $customField['value']));
                                }
                                break;
                            case 4:
                                $document['typo3_version'] = $customField['value'] ?? '-';
                                break;
                            case 5:
                                $document['php_version'] = $customField['value'] ?? '-';
                                break;
                            case 8:
                                $document['complexity'] = $customField['value'] ?? '-';
                                break;
                            case 15:
                                // custom field for regression could not be set, empty, 0 or 1.
                                $document['isregression'] = ($customField['value'] ?? '0') === '1';
                                break;
                            case 18:
                                $document['focus']['name'] = $customField['value'] ?? '-';
                                break;
                            default:
                        }
                    }
                }
                $document['updated_on'] = $this->fixDateFormat($document['updated_on'], 'Redmine');
                $document['created_on'] = $this->fixDateFormat($document['created_on'], 'Redmine');
                unset($document['start_date'], $document['due_date']);
                $elasticType = ElasticUtility::getIndexByName('issue');
                break;
            case 'review':
                $elasticType = ElasticUtility::getIndexByName('review');
                break;
            case 'interactions':
                $elasticType = ElasticUtility::getIndexByName('interactions');
                break;
            default:
        }
        if (null !== $elasticType) {
            if (isset($document['interaction_id'])) {
                $doc = new Document($document['interaction_id'] . '-' . $document['interaction_type'], $document);
            } else {
                $doc = new Document((string) $document['id'], $document);
            }
            $elasticType->addDocument($doc);
            $this->recorder->document();
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    private function fixDateFormat(string $dateTimeString, string $source): string
    {
        switch ($source) {
            case 'Redmine':
                break;
            case 'Gerrit':
                $subString = substr($dateTimeString, 0, 19);
                $dateTimeString = $subString;
                break;
            default:
                throw new \InvalidArgumentException('Value of $source has to be either "Redmine" or "Gerrit"');

        }

        return new \DateTime($dateTimeString)->format('Y/m/d H:i:s');
    }

    private function checkIfMessageIsComment(string $message): bool
    {
        // Is just a Code Review
        $pattern = '^Patch\sSet\s\d{1,4}:\s.*(Code-Review)(-|\+)(1|2)$';
        $isCodeReview = $this->checkRegex($message, $pattern, '');
        // Is just a Verification
        $pattern = '^Patch\sSet\s\d{1,4}:\s.*(Verified)(-|\+)(1|2)$';
        $isVerification = $this->checkRegex($message, $pattern, '');
        // Is just a Verification
        $pattern = '^Uploaded\spatch\sset\s\d{1,4}.$';
        $isPatchset = $this->checkRegex($message, $pattern, '');

        $pattern = '^Patch\sSet\s\d{1,4}:\sCommit message was updated\.$';
        $isCommitMessageChange = $this->checkRegex($message, $pattern, '');

        // Check all booleans and if one hits, return false
        return !($isCodeReview || $isVerification || $isPatchset || $isCommitMessageChange || $this->checkIfMessageIsBackport($message) || $this->checkIfMessageIsMerge($message));
    }

    private function checkIfMessageIsCodeReview(string $message): bool
    {
        $pattern = '^Patch\sSet\s\d{1,4}:\s.*(Code-Review)(-|\+)(1|2)';

        return $this->checkRegex($message, $pattern);
    }

    private function checkIfMessageIsVerification(string $message): bool
    {
        $pattern = '^Patch\sSet\s\d{1,4}:\s.*(Verified)(-|\+)(1|2)';

        return $this->checkRegex($message, $pattern);
    }

    private function checkIfMessageIsAbandoning(string $message): bool
    {
        $pattern = '^Abandoned';

        return $this->checkRegex($message, $pattern);
    }

    private function checkIfMessageIsMerge(string $message): bool
    {
        $pattern = 'Change has been successfully cherry-picked as \b[0-9a-f]{40}\b';

        return $this->checkRegex($message, $pattern);
    }

    private function checkIfMessageIsBackport(string $message): bool
    {
        $pattern = 'This patchset was cherry picked to branch.*? as commit \b[0-9a-f]{40}\b';

        return $this->checkRegex($message, $pattern);
    }

    private function checkRegex(string $string, string $pattern, $modifier = 'm'): bool
    {
        preg_match('/' . $pattern . '/' . $modifier, $string, $matches);

        return isset($matches[0]);
    }

    private function buildRevisionArray(array $data, string $interactionType, string $messageId): array
    {
        $interaction = [];
        $interaction['interaction_id'] = $messageId . '-' . $interactionType;
        $interaction['interaction_type'] = $interactionType;
        $interaction['account'] = $data['uploader']['username'];
        $interaction['fullname'] = $data['uploader']['name'];
        if (isset($data['uploader']['email'])) {
            $interaction['email'] = $data['uploader']['email'];
        } else {
            $interaction['email'] = 'Unknown';
        }

        return $interaction;
    }

    /**
     * Calls Forge, waits and retries while it rate-limits (429), and fails on any other error status.
     *
     * The pause doubles from 10 seconds up to 5 minutes, about 10 minutes in all: enough for a full import
     * running unattended over night not to end at a rate limit. A delta waiting that long holds its lock,
     * the next delta is skipped meanwhile.
     *
     * The Redmine client returns the body of an error response (e.g. the HTML page of a 429) instead of
     * throwing, so the status of the last response decides.
     *
     * @throws \RuntimeException
     */
    private function callRedmine(callable $callback, int $attempt = 0): mixed
    {
        try {
            $result = $callback();
            $status = $this->forgeClient->getLastResponseStatusCode();
        } catch (UnexpectedResponseException $e) {
            $result = null;
            $status = $e->getResponse()->getStatusCode();
        }
        if (429 === $status && $attempt < self::FORGE_RETRIES) {
            ++$attempt;
            // Give Forge some time to relax
            $sleepTime = min(10 * 2 ** ($attempt - 1), 300);
            $this->logger->warning(sprintf('[RECV] Failed to fetch data from Forge, response code %s, retrying in %d seconds, attempt #%d...', $status, $sleepTime, $attempt));
            $this->recorder->progress(sprintf('Forge rate limit, waiting %d s (attempt %d of %d)', $sleepTime, $attempt, self::FORGE_RETRIES));
            sleep($sleepTime);
            $this->refreshLock();

            return $this->callRedmine($callback, $attempt);
        }
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Forge responded with status %d', $status), $status);
        }

        return $result;
    }

    /**
     * @param string|null $updatedOn a Redmine filter, see ImportWatermark::redmineFilter(); with a filter the oldest come first
     */
    private function getAllForgeIssuesPaginated(int $limit, int $offset, ?string $updatedOn = null): array
    {
        $parameters = [
            'limit' => $limit,
            'offset' => $offset,
            'project_id' => $this->parameters->get('redmine_project_id'),
            'status_id' => '*',
            'sort' => null === $updatedOn ? 'updated_on:desc' : 'updated_on',
        ];
        if (null !== $updatedOn) {
            $parameters['updated_on'] = $updatedOn;
        }

        return $this->callRedmine(fn () => $this->forgeClient->getApi('issue')->list($parameters));
    }

    /**
     * @param list<string> $options the details Gerrit adds to every change, none for just the list
     */
    private function getCommitsFromGerrit(int $perRun, int $current, string $query = self::GERRIT_PROJECT, array $options = self::GERRIT_DETAIL_OPTIONS, int $retry = 0): ResponseInterface
    {
        try {
            $httpQueryData = [
                'q' => $query,
                'n' => $perRun,
                'start' => $current,
                'o' => $options,
            ];
            $query = [];
            // http_build_query() doesn't work as it generates o[0]=a&o[1]=b&o[2]=c instead of o=a&o=b&o=c
            foreach ($httpQueryData as $name => $value) {
                $value = (array) $value;
                array_walk_recursive($value, static function ($value) use (&$query, $name) {
                    $query[] = urlencode($name) . '=' . urlencode((string) $value);
                });
            }
            $httpQuery = implode('&', $query);
            $url = sprintf('/changes/?%s', $httpQuery);

            return $this->client->request('GET', $url);
        } catch (ServerException|ClientException $e) {
            $status = $e->getResponse()->getStatusCode();
            // Gerrit rate-limits the heavy change queries now and then with 429
            if ((429 === $status || $e instanceof ServerException) && $retry <= 3) {
                ++$retry;
                $retryAfter = (int) $e->getResponse()->getHeaderLine('Retry-After');
                $sleepTime = $retryAfter > 0 ? min($retryAfter, 300) : 30 * $retry;
                $this->logger->warning(sprintf('Gerrit responded with %d, retrying in %d seconds (attempt %d)...', $status, $sleepTime, $retry));
                sleep($sleepTime);

                return $this->getCommitsFromGerrit($perRun, $current, $query, $options, $retry);
            }

            throw new \RuntimeException(sprintf('Fetching changes from Gerrit failed with status %d', $status), 0, $e);
        }
    }

    /**
     * Shows the progress of the console on the command runs page too.
     */
    private function reportProgress(string $phase, ProgressBar $progressBar): void
    {
        $this->recorder->progress($phase, $progressBar->getProgress(), $progressBar->getMaxSteps() > 0 ? $progressBar->getMaxSteps() : null);
    }

    /**
     * Refreshes the lock to avoid a too-early expiration of its TTL.
     */
    private function refreshLock(): void
    {
        $this->lock?->refresh();
    }
}
