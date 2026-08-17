<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Service\GithubService;
use Elastica\Document;
use Exception;
use GuzzleHttp\ClientInterface;
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
use T3G\ElasticUtility\ElasticUtility;

#[AsCommand(name: 'app:import', description: 'Imports data from Redmine (forge.typo3.org), Gerrit (review.typo3.org) and Github')]
class ImportCommand extends Command implements LoggerAwareInterface
{
    use LockableTrait;
    use LoggerAwareTrait;
    protected const BAMBOO_USER_ID = [49454, 51735, 52386];
    protected static array $importModes = ['forgeFull', 'forgeDelta', 'reviewFull', 'reviewDelta', 'github', 'githubDelta'];
    private array $userCache = [];
    private bool $moreIssuesFromGerrit = true;
    protected OutputInterface $output;

    public function __construct(
        private readonly ParameterBagInterface $parameters,
        private readonly Client $forgeClient,
        private readonly GithubService $githubService,
        private readonly ClientInterface $client
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
        if (!in_array($mode, self::$importModes, true)) {
            $io->note('Please select one of the modes: "' . implode('" ', self::$importModes) . '"');
        }

        try {
            switch ($mode) {
                case 'forgeFull':
                    if ($this->lock('forge')) {
                        try {
                            $this->importFromForge(100, $startAt);
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another forge import');

                        return Command::SUCCESS;
                    }
                    break;
                case 'forgeDelta':
                    if ($this->lock('forge')) {
                        try {
                            $this->importFromForge(100, $startAt, true);
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another forge import');

                        return Command::SUCCESS;
                    }

                    break;
                case 'reviewDelta':
                    if ($this->lock('gerrit')) {
                        try {
                            $mainSection = $output->section();
                            $subSection = $output->section();
                            $progressBar = new ProgressBar($mainSection, 100);
                            $subBar = new ProgressBar($subSection);
                            $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
                            $result = $this->getReviewBlock(0, 100, $subBar, $progressBar);
                            foreach ($result as $review) {
                                $this->addDocumentToElastic($review, 'review');
                            }
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another gerrit import');

                        return Command::SUCCESS;
                    }
                    break;
                case 'reviewFull':
                    if ($this->lock('gerrit')) {
                        try {
                            $totalCount = 30000;
                            $currentSlot = 0;
                            if ($startAt > 0) {
                                $currentSlot = $startAt;
                            }
                            $perRun = 100;
                            $mainSection = $output->section();
                            $subSection = $output->section();
                            $progressBar = new ProgressBar($mainSection, $totalCount);
                            $progressBar->setProgress($currentSlot);
                            $subBar = new ProgressBar($subSection);
                            $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
                            while ($currentSlot <= $totalCount) {
                                if ($this->moreIssuesFromGerrit) {
                                    $result = $this->getReviewBlock($currentSlot, $perRun, $subBar, $progressBar);
                                    foreach ($result as $review) {
                                        $this->addDocumentToElastic($review, 'review');
                                    }
                                }
                                $currentSlot += $perRun;
                            }
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another gerrit import');

                        return Command::SUCCESS;
                    }
                    break;
                case 'github':
                    if ($this->lock('github')) {
                        try {
                            $organization = $input->getOption('organization');
                            $repo = $input->hasOption('repository') ? $input->getOption('repository') : null;
                            $this->importFromGithub($organization, $repo, $io);
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another github import');
                    }

                    return Command::SUCCESS;
                case 'githubDelta':
                    if ($this->lock('github')) {
                        try {
                            $organization = $input->getOption('organization');
                            $repo = $input->hasOption('repository') ? $input->getOption('repository') : null;
                            $pageCount = 1;
                            $this->importFromGithub($organization, $repo, $io, $pageCount);
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another github import');
                    }

                    return Command::SUCCESS;
            }
        } catch (\Exception $exception) {
            // If any exception occurs, release the lock, else the process will run never again.
            $this->logger->error($exception->getMessage());
            $output->writeln('Release lock because of exception: ' . $exception->getMessage());
            $this->release();

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function importFromForge(int $limit, int $startOffset = 0, bool $importDelta = false): void
    {
        $offset = 0;
        if ($startOffset > 0) {
            $offset = $startOffset;
        }

        $data = $this->getAllForgeIssuesPaginated($limit, $offset);
        $totalCount = $importDelta ? $limit : (int) $data['total_count'];
        $progressBar = new ProgressBar($this->output, $totalCount - $offset);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        while ($offset < $totalCount) {
            $result = $this->getAllForgeIssuesPaginated($limit, $offset);
            // break a soon as the API returns no more issues
            if (0 === (is_countable($result['issues']) ? count($result['issues']) : 0)) {
                break;
            }
            foreach ($result['issues'] as $doc) {
                try {
                    $this->getTicketAndJournal($doc);
                    $this->refreshLock();
                } catch (\Exception) {
                } finally {
                    // Stephan Großberndt asked for a sleep of between 50 - 100ms between requests to Forge on April 15th, 2024 in Slack channel #t3a-t3g-infra
                    // Since we run into a lot of requests here, 50ms should be fine to not block the overall import process for too long
                    usleep(50000);
                }
                $progressBar->advance();
            }
            $offset += $limit;
        }
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
        // import journals only if issue status is "Under review" (ID: 8)
        if (isset($issue['status']['id']) && 8 === (int) $issue['status']['id']) {
            $issueWithJournals = $this->callRedmine(function () use ($issue) {
                return $this->forgeClient->getApi('issue')->show($issue['id'], ['include' => ['journals']]);
            });
            if (is_array($issueWithJournals)) {
                $this->addDocumentToElastic($this->injectUserData($issueWithJournals['issue']));
            }
        } else {
            $this->addDocumentToElastic($this->injectUserData($issue));
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
            }
            foreach ($this->githubService->getInteractionsForIssuesInRepository($organization, $repo, $pageCount) as $interaction) {
                $this->addDocumentToElastic($interaction, 'interactions');
                $progressBar->advance();
            }
        } catch (\Exception $e) {
            // GitHub respond sometimes with 500 errors, so we have to catch them and continue
            // A very wired case is that page 3 and 4 throw 500 errors, but page 5 and higher work again.
            // It looks like the GitHub API is not stable and have a bug 😳
            $io->error($e->getMessage());
        }
        $progressBar->finish();
        $io->newLine(1);
    }

    private function injectUserData(array $issue): array
    {
        // @todo maybe the runtime cache ain't the smartest thing here
        // The author could be empty, so we have to check for that
        $userId = $issue['author']['id'] ?? null;
        if (null !== $userId && !isset($this->userCache[$userId])) {
            $userRecord = $this->callRedmine(function () use ($userId) {
                return $this->forgeClient->getApi('user')->show($userId);
            });
            $this->userCache[$userId] = $userRecord['user']['mail'] ?? '';
        }
        $issue['author']['email'] = $this->userCache[$userId] ?? '';

        return $issue;
    }

    /**
     * @throws \Exception
     */
    protected function getReviewBlock(int $current, int $perRun, ProgressBar $subBar, ProgressBar $mainProgressbar): array
    {
        $moreChanges = false;
        $out = [];
        try {
            $response = $this->getCommitsFromGerrit($perRun, $current);
            $data = json_decode(str_replace(")]}'", '', (string) $response->getBody()), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            $this->logger->warning($e->getMessage());

            return $out;
        }
        foreach ($data as $change) {
            $mainProgressbar->advance();
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

                    if (!isset($message['_revision_number'])) {
                        $message['_revision_number'] = $patchSetNumber;
                    }

                    // Check if is merge-commit
                    if ($this->checkIfMessageIsMerge($message['message'])) {
                        $revisions = $change['revisions'];
                        usort($revisions, function ($a, $b) {
                            $a = new \DateTime($this->fixDateFormat($a['created'], 'Gerrit'));
                            $b = new \DateTime($this->fixDateFormat($b['created'], 'Gerrit'));

                            return $a->getTimestamp() <=> $b->getTimestamp();
                        });

                        $mergeRevision = $revisions[array_key_last($revisions)];
                        $this->addDocumentToElastic($this->buildRevisionArray($mergeRevision, 'core:review:merge', $messageId) + $interaction, 'interactions');

                        // If there is more than one revision, we want to give credit to both the original author
                        // and the person that patched this change with improvements (in case that is a different person)
                        if ((is_countable($revisions) ? count($revisions) : 0) > 1) {
                            $firstRevision = $revisions[array_key_first($revisions)];
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

            $reviewTags = $this->extractReviewTags($change['subject']);

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
                'work_in_progress' => in_array('WIP', $reviewTags, true) || ($change['work_in_progress'] ?? false),
            ];
            if (array_key_exists('_more_changes', $change)) {
                $moreChanges = true;
            }
        }
        if (false === $moreChanges) {
            $this->moreIssuesFromGerrit = false;
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
        $releaseArray = array_map('trim', explode(',', $matches[1]));
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

    protected function extractReviewTags(string $message): array
    {
        $pattern = '/\[([a-zA-Z0-9]*)\]/';
        preg_match_all($pattern, $message, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match) => $match[1], $matches);
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
                                    $document['tags'] = array_map('trim', explode(',', (string) $customField['value']));
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
            case 'user':
                $elasticType = ElasticUtility::getIndexByName('user');
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

        return (new \DateTime($dateTimeString))->format('Y/m/d H:i:s');
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

    private function callRedmine(callable $callback, int $attempt = 0): mixed
    {
        try {
            return $callback();
        } catch (UnexpectedResponseException $e) {
            if (429 === $e->getResponse()->getStatusCode() && $attempt <= 3) {
                ++$attempt;
                // Give Forge some time to relax
                $sleepTime = 10 * $attempt;
                $this->logger->warning(sprintf('[RECV] Failed to fetch data from Forge, response code %s, retrying in %d seconds, attempt #%d...', $e->getResponse()->getStatusCode(), $sleepTime, $attempt));
                sleep($sleepTime);

                return $this->callRedmine($callback, $attempt);
            }

            throw $e;
        }
    }

    private function getAllForgeIssuesPaginated(int $limit, int $offset): array
    {
        return $this->callRedmine(function () use ($limit, $offset) {
            return $this->forgeClient->getApi('issue')->list([
                'limit' => $limit,
                'offset' => $offset,
                'project_id' => $this->parameters->get('redmine_project_id'),
                'status_id' => '*',
                'sort' => 'updated_on:desc',
            ]);
        });
    }

    private function getCommitsFromGerrit(int $perRun, int $current, $retry = 0): ResponseInterface
    {
        try {
            $httpQueryData = [
                'q' => 'project:Packages/TYPO3.CMS',
                'n' => $perRun,
                'start' => $current,
                'o' => ['DETAILED_LABELS', 'ALL_REVISIONS', 'ALL_FILES', 'CURRENT_COMMIT', 'MESSAGES', 'DETAILED_ACCOUNTS'],
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
        } catch (ServerException $e) {
            if ($retry <= 3) {
                ++$retry;
                // Give Gerrit some time to relax
                $sleepTime = 5 * $retry;
                $this->logger->warning(sprintf('Failed to fetch data from Gerrit, retrying in %d seconds...', $sleepTime));
                sleep($sleepTime);

                return $this->getCommitsFromGerrit($perRun, $current, $retry);
            }

            throw $e;
        }
    }

    /**
     * Refreshes the lock to avoid a too-early expiration of its TTL.
     */
    private function refreshLock(): void
    {
        $this->lock?->refresh();
    }
}
