<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Client\ElasticClient;
use App\Client\ForgeClient;
use App\Service\DiscordService;
use DateTime;
use Elastica\Document;
use Elastica\Exception\NotFoundException;
use Elastica\Type as DocType;
use Exception;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Woeler\DiscordPhp\Message\DiscordEmbedsMessage;

class ImportCommand extends Command
{
    use LockableTrait;

    protected static $defaultName = 'app:import';

    protected const BAMBOO_USER_ID = [49454, 51735];

    protected static $importModes = ['forgeFull', 'forgeDelta', 'reviewFull', 'reviewDelta'];
    /**
     * @var ElasticClient
     */
    private $elasticClient;

    private $userCache = [];
    /**
     * @var bool
     */
    private $moreIssuesFromGerrit = true;
    /**
     * @var LoggerInterface
     */
    private $logger;

    protected function configure(): void
    {
        $this
            ->setDescription('Imports data from both Redmine (forge.typo3.org) and Gerrit (review.typo3.org)')
            ->addOption(
                'mode',
                null,
                InputOption::VALUE_REQUIRED,
                'Import Mode: Allowed values are \'forgeFull\', \'forgeDelta\', \'reviewFull\', \'reviewDelta\''
            )
            ->addOption(
                'startAt',
                null,
                InputOption::VALUE_OPTIONAL,
                'at which line should the script start',
                0
            )
            ->addOption(
                'sendnotifications',
                null,
                InputOption::VALUE_OPTIONAL,
                'Will not send notifications to Discord in silent mode',
                false
            );
    }

    /**
     * @var ParameterBagInterface
     */
    protected $parameters;

    /**
     * @var ForgeClient
     */
    protected $forgeClient;

    /**
     * @var DiscordService
     */
    private $discordService;

    /**
     * @var string
     */
    private $discordWebhook;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var bool
     */
    private $sendNotifications = false;

    public function __construct(
        ParameterBagInterface $parameters,
        ForgeClient $forgeClient,
        ElasticClient $elasticClient,
        DiscordService $discordService,
        LoggerInterface $logger,
        string $discordWebhook
    ) {
        parent::__construct();
        $this->parameters = $parameters;
        $this->forgeClient = $forgeClient;
        $this->elasticClient = $elasticClient;
        $this->discordService = $discordService;
        $this->discordWebhook = $discordWebhook;
        $this->logger = $logger;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $this->output = $output;
        $mode = $input->getOption('mode');
        $startAt = (int)$input->getOption('startAt');
        $this->sendNotifications = ($input->getOption('sendnotifications') !== false);
        if (!in_array($mode, self::$importModes, true)) {
            $io->note('Please select one of the modes: "' . implode('" ', self::$importModes) . '"');
        }

        switch ($mode) {
            case 'forgeFull':
                if ($this->lock('forge')) {
                    $this->importFromForge(100, $startAt);
                    $this->release();
                } else {
                    $output->writeln('Locked by another forge import');
                    return 0;
                }
                break;
            case 'forgeDelta':
                if ($this->lock('forge')) {
                    $this->importFromForge(100, $startAt, true);
                    $this->release();
                } else {
                    $output->writeln('Locked by another forge import');
                    return 0;
                }
                break;
            case 'reviewDelta':
                if ($this->lock('gerrit')) {
                    $mainSection = $output->section();
                    $subSection = $output->section();
                    $progressBar = new ProgressBar($mainSection, 100);
                    $subBar = new ProgressBar($subSection);
                    $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
                    /** @noinspection PhpUnhandledExceptionInspection */
                    $result = $this->getReviewBlock(0, 100, $subBar, $progressBar);
                    foreach ($result as $review) {
                        /** @noinspection PhpUnhandledExceptionInspection */
                        $this->addDocumentToElastic($review, 'review');
                    }
                    $this->release();
                } else {
                    $output->writeln('Locked by another gerrit import');
                    return 0;
                }
                break;
            case 'reviewFull':
                if ($this->lock('gerrit')) {
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
                            /** @noinspection PhpUnhandledExceptionInspection */
                            $result = $this->getReviewBlock($currentSlot, $perRun, $subBar, $progressBar);
                            foreach ($result as $review) {
                                /** @noinspection PhpUnhandledExceptionInspection */
                                $this->addDocumentToElastic($review, 'review');
                            }
                        }
                        $currentSlot += $perRun;
                    }
                    $this->release();
                } else {
                    $output->writeln('Locked by another gerrit import');
                    return 0;
                }
                break;
        }
    }

    private function importFromForge(int $perRun, int $startOffset = 0, bool $importDelta = false): void
    {
        $currentSlot = 0;
        if ($startOffset > 0) {
            $currentSlot = $startOffset;
        }
        /** @noinspection PhpUndefinedMethodInspection */
        $data = $this->forgeClient->api('issue')->all(
            [
                'limit' => $perRun,
                'offset' => $currentSlot,
                'project_id' => $this->parameters->get('redmine_project_id'),
                'status_id' => '*',
                'sort' => 'updated_on:desc',
            ]
        );
        if ($importDelta) {
            $totalCount = $perRun;
        } else {
            $totalCount = (int)$data['total_count'];
        }
        $progressBar = new ProgressBar($this->output, $totalCount - $currentSlot);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        while ($currentSlot < $totalCount) {
            /** @noinspection PhpUndefinedMethodInspection */
            $result = $this->forgeClient->api('issue')->all(
                [
                    'limit' => $perRun,
                    'offset' => $currentSlot,
                    'project_id' => $this->parameters->get('redmine_project_id'),
                    'status_id' => '*',
                    'sort' => 'updated_on:desc',
                ]
            );
            // break a soon as the API returns no more issues
            if (count($result['issues']) === 0) {
                break;
            }
            foreach ($result['issues'] as $doc) {
                try {
                    $this->getTicketAndJournal($doc);
                } catch (Exception $e) {
                }
                /** @noinspection DisconnectedForeachInstructionInspection */
                $progressBar->advance();
            }
            $currentSlot += $perRun;
        }
    }

    /**
     * Imports a single ticket.
     * A sleep(1) might be useful here to not hammer forge.
     *
     * @param array $issue
     *
     * @throws InvalidArgumentException
     * @throws Exception
     */
    private function getTicketAndJournal(array $issue): void
    {
        //import journals only if issue status is "Under review" (ID: 8)
        if (isset($issue['status']['id']) && (int) $issue['status']['id'] === 8) {
            /** @noinspection PhpUndefinedMethodInspection */
            $issueWithJournals = $this->forgeClient->api('issue')->show($issue['id'], ['include' => ['journals']]);
            if (is_array($issueWithJournals)) {
                $this->addDocumentToElastic($this->injectUserData($issueWithJournals['issue']));
            }
        } else {
            $this->addDocumentToElastic($this->injectUserData($issue));
        }
    }

    private function injectUserData(array $issue): array
    {
        //@todo maybe the runtime cache ain't the smartest thing here
        $userId = $issue['author']['id'];
        if (!isset($this->userCache[$userId])) {
            /** @noinspection PhpUndefinedMethodInspection */
            $userRecord = $this->forgeClient->api('user')->show($userId);
            $this->userCache[$userId] = $userRecord['user']['mail'];
        }
        $issue['author']['email'] = $this->userCache[$userId];
        return $issue;
    }

    /**
     * @param int $current
     * @param int $perRun
     * @param ProgressBar $subBar
     * @param ProgressBar $mainProgressbar
     * @return array
     *
     * @throws Exception
     */
    protected function getReviewBlock(int $current, int $perRun, ProgressBar $subBar, ProgressBar $mainProgressbar) : array
    {
        $moreChanges = false;
        $out = [];
        $json = file_get_contents('https://review.typo3.org/changes/?q=project:Packages/TYPO3.CMS&n=' . $perRun .
            '&start=' . $current . '&o=DETAILED_LABELS&o=ALL_REVISIONS&o=ALL_FILES&o=CURRENT_COMMIT&o=MESSAGES&o=DETAILED_ACCOUNTS');
        $data = json_decode(str_replace(")]}'", '', $json), true);
        $foo = '';
        foreach ($data as $change) {
            /** @noinspection DisconnectedForeachInstructionInspection */
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
            $subBar->start(count($change['messages']));
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

                    // Check if is merge
                    if ($this->checkIfMessageIsMerge($message['message'])) {
                        $revisions = $change['revisions'];
                        usort($revisions, function ($a, $b) {
                            $a = new DateTime($this->fixDateFormat($a['created'], 'Gerrit'));
                            $b = new DateTime($this->fixDateFormat($b['created'], 'Gerrit'));

                            if ($a->getTimestamp() === $b->getTimestamp()) {
                                return 0;
                            }

                            return ($a->getTimestamp() < $b->getTimestamp()) ? -1 : 1;
                        });

                        $mergeRevision = $revisions[array_key_last($revisions)];
                        $this->addDocumentToElastic($this->buildRevisionArray($mergeRevision, 'core:review:merge', $messageId) + $interaction, 'interactions');

                        // If there is more than one revision, we want to give credit to both the original author
                        // and the person that patched this change with improvements (in case that is a different person)
                        if (count($revisions) > 1) {
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
                } catch (Exception $e) {
                    if (
                        strpos($message['message'], 'Change could not be merged due to a path conflict') === false
                        &&
                        strpos($message['message'], 'The change could not be merged due to a path conflict') === false
                        &&
                        strpos($message['message'], 'Your change could not be merged due to a path conflict') === false
                        &&
                        strpos($message['message'], 'Change has been successfully pushed into branch sandbox') === false
                        &&
                        strpos($message['message'], 'Change could not be merged because of a missing dependency') === false
                    ) {
                        throw new RuntimeException($e->getMessage());
                    }
                }
                /** @noinspection DisconnectedForeachInstructionInspection */
                $subBar->advance();
            }

            $author = $change['revisions'][$change['current_revision']]['commit']['author']['name'];
            $authorEmail = $change['revisions'][$change['current_revision']]['commit']['author']['email'];
            $filesInPatchset = array_keys($change['revisions'][$change['current_revision']]['files']);
            if ((int)$change['_number'] === 11918) {
                $change['revisions'][$change['current_revision']]['commit']['message'] = '6.0';
            }

            $reviewTags = $this->extractReviewTags($change['subject']);

            $out[$change['_number']] = [
                'id' => $change['_number'],
                'change_id' => $change['change_id'],
                'reviewstatus' => $change['status'],
                'subject' => $change['subject'],
                'branch' => $change['branch'],
                'topic' => array_key_exists('topic', $change) ? $change['topic'] : 'no topic',
                'mergeable' => ($change['mergeable'] === true) ? 'yes' : 'no',
                'affected_files' => count($change['revisions'][$change['current_revision']]['files']),
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
        if ($moreChanges === false) {
            $this->moreIssuesFromGerrit = false;
        }
        return $out;
    }

    /**
     * @param string $message
     *
     * @return array
     *@throws Exception
     *
     */
    protected function extractReleases($message) : array
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
        /*
         * fix Stuff
         */
        if (false !== strpos($matches[1], 'master.')) {
            $matches[1] = str_replace('master.', 'master,', $matches[1]);
        }
        if (false !== strpos($matches[1], '4.7.')) {
            $matches[1] = str_replace('4.7.', '4.7,', $matches[1]);
        }
        if (false !== strpos($matches[1], '4.5 LTS')) {
            $matches[1] = str_replace('4.5 LTS', '4.5', $matches[1]);
        }
        $releaseArray = $this->trimExplode(',', $matches[1]);
        foreach ($releaseArray as $key => $release) {
            if (false !== strpos($release, ' ')) {
                throw new InvalidArgumentException('Release contains space? ' . $message);
            }
            $releaseArray[$key] = strtolower($release);
        }

        return $releaseArray;
    }

    /**
     * @param array $labels
     *
     * @return array
     *@throws Exception
     *
     */
    protected function extractLabels(array $labels) : array
    {
        $out = [
            'positive_reviews' => 0,
            'negative_reviews' => 0,
            'positive_verifications' => 0,
            'negative_verifications' => 0,

        ];
        foreach ($labels as $labelType => $subRow) {
            switch ($labelType) {
                case 'Verified':
                    $key = '_verifications';
                    break;
                case 'Code-Review':
                    $key = '_reviews';
                    break;
                default:
                    throw new InvalidArgumentException('Unknown Label type ' . $labelType);
            }
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
                                throw new InvalidArgumentException('Unknown Vote Type');
                        }
                    }
                }
            }
        }

        return $out;
    }

    protected function extractReviewTags(string $message): array
    {
        $patchTags = [];
        $pattern = '/\[([a-zA-Z0-9]*)\]/';
        preg_match_all($pattern, $message, $matches, PREG_SET_ORDER);
        if (is_array($matches)) {
            foreach ($matches as $index => $match) {
                $patchTags[] = $match[1];
            }
        }
        return $patchTags;
    }

    /**
     * Explodes a string and trims all values for whitespace in the ends.
     * If $onlyNonEmptyValues is set, then all blank ('') values are removed.
     *
     * @param string $delim             Delimiter string to explode with
     * @param string $string            The string to explode
     * @param bool   $removeEmptyValues If set, all empty values will be removed in output

     * @return array Exploded values
     */
    public function trimExplode($delim, $string, $removeEmptyValues = false) : array
    {
        $explodedValues = explode($delim, $string);

        $result = array_map('trim', $explodedValues);

        if ($removeEmptyValues) {
            $temp = [];
            foreach ($result as $value) {
                if ($value !== '') {
                    $temp[] = $value;
                }
            }
            $result = $temp;
        }

        return $result;
    }

    /**
     * @param array  $document
     * @param string $type
     *
     * @throws Exception
     */
    protected function addDocumentToElastic(array $document, $type = 'issue'): void
    {
        $elasticType = null;
        switch ($type) {
            case 'issue':
                if (isset($document['custom_fields'])) {
                    foreach ($document['custom_fields'] as $customField) {
                        switch ($customField['id']) {
                            case 3:
                                if (!empty($customField['value'])) {
                                    $document['tags'] = array_map('trim', explode(',', $customField['value']));
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
                                $document['isregression'] = isset($customField['value']);
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
                $elasticType = new DocType($this->elasticClient->getIndex($this->parameters->get('elastic_index_name')), 'issue');
                if ($this->sendNotifications) {
                    try {
                        $elasticType->getDocument($document['id']);
                    } catch (NotFoundException $e) {
                        $this->sendDiscordMessage($document);
                    }
                }
                break;
            case 'review':
                $elasticType = new DocType($this->elasticClient->getIndex($this->parameters->get('elastic_index_name')), 'review');
                break;
            case 'user':
                $elasticType = new DocType($this->elasticClient->getIndex($this->parameters->get('elastic_index_name')), 'user');
                break;
            case 'interactions':
                $elasticType = new DocType($this->elasticClient->getIndex($this->parameters->get('elastic_index_name')), 'interactions');
                break;
            default:
        }
        if ($elasticType !== null) {
            if (isset($document['interaction_id'])) {
                $doc = new Document($document['interaction_id'] . '-' . $document['interaction_type'], $document);
            } else {
                $doc = new Document($document['id'], $document);
            }
            $elasticType->addDocument($doc);
        }
    }

    /**
     * @param string $dateTimeString
     * @param string $source
     *
     * @return string
     * @throws InvalidArgumentException
     * @throws Exception
     */
    protected function fixDateFormat(string $dateTimeString, string $source) : string
    {
        switch ($source) {
            case 'Redmine':
                break;
            case 'Gerrit':
                $subString = substr($dateTimeString, 0, 19);
                $dateTimeString = is_string($subString) ? $subString : $dateTimeString;
                break;
            default:
                throw new InvalidArgumentException('Value of $source has to be either "Redmine" or "Gerrit"');

        }
        $dateTime = new DateTime($dateTimeString);
        if (!$dateTime) {
            throw new InvalidArgumentException('Date format for "' . $dateTimeString . '" could not be parsed');
        }
        return $dateTime->format('Y/m/d H:i:s');
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
        if (isset($matches[0])) {
            return true;
        }
        return false;
    }

    /**
     * @param array $data
     * @param string $interactionType
     * @param string $messageId
     * @return array
     */
    private function buildRevisionArray(array $data, string $interactionType, string $messageId): array
    {
        $interaction['interaction_id'] = $messageId . '-' . $interactionType;
        $interaction['interaction_type'] = $interactionType;
        $interaction['account'] = $data['uploader']['username'];
        $interaction['fullname'] = $data['uploader']['name'];
        if (isset($mergeRevision['uploader']['email'])) {
            $interaction['email'] = $data['uploader']['email'];
        } else {
            $interaction['email'] = 'Unknown';
        }

        return $interaction;
    }

    /**
     * @param array $data
     */
    private function sendDiscordMessage(array $data): void
    {
        $message = (new DiscordEmbedsMessage())
            ->setTitle('Forger found a new issue')
            ->setAuthorName($data['author']['name'] ?? 'Unknown')
            ->setDescription('[' . $data['subject'] . '](https://forge.typo3.org/issues/' . $data['id'] . ')'
                . PHP_EOL . PHP_EOL . substr(strip_tags($data['description']), 0, 1500))
            ->addField('Status', $data['status']['name'], true)
            ->addField('Tracker', $data['tracker']['name'], true)
            ->setColorWithHexValue('32CD32');
        if ($data['tracker']['name'] === 'Bug') {
            $message->setColorWithHexValue('B22222');
        }
        $message->setUsername('Forger');

        try {
            $this->discordService->sendMessage($message, $this->discordWebhook);
        } catch (BadResponseException $e) {
            $this->logger->warning(
                'Could not deliver message to Discord',
                [
                    'statusCode' => $e->getResponse()->getStatusCode(),
                    'responseBody' => (string)$e->getResponse()->getBody(),
                ]
            );
        } catch (GuzzleException $e) {
        }
    }
}
