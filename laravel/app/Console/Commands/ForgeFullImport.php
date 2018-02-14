<?php

namespace App\Console\Commands;

use App\Elastic\Connection;
use App\Redmine\Connection as RedmineConnection;
use Elastica\Document;
use Elastica\Exception\NotFoundException;
use Elastica\Type as DocType;
use GuzzleHttp\Client;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class ForgeFullImport
 */
class ForgeFullImport extends Command
{
    const BAMBOO_USER_ID = 49454;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'Forger:Import {--startAt=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Central data import functions';

    /**
     * @var \Illuminate\Config\Repository
     */
    protected $config;

    /**
     * @var \Redmine\Client
     */
    protected $redmineClient;

    /**
     * @var \Redmine\Client
     */
    protected $elevatedRedmineClient;

    /**
     * @var \Elastica\Client
     */
    protected $elasticClient;

    /**
     * @var \Elastica\Index;
     */
    protected $elasticIndex;

    /**
     * @var Connection
     */
    protected $elasticConnection;

    /**
     * @var RedmineConnection
     */
    protected $redmineConnection;

    /**
     * @var bool
     */
    protected $moreIssuesFromGerrit = true;

    private $totalStart;

    private $totalEnd;

    private $blockStart;

    private $blockEnd;

    private $interactionCount = 0;

    private $reviewCount = 0;

    /**
     * ForgeFullImport constructor.
     *
     * @param Repository        $config
     * @param Connection        $elasticConnection
     * @param RedmineConnection $redmineConnection
     */
    public function __construct(
        Repository $config,
        Connection $elasticConnection,
        RedmineConnection $redmineConnection
    ) {
        parent::__construct();
        $this->config = $config;
        $this->elasticConnection = $elasticConnection;
        $this->redmineConnection = $redmineConnection;
    }

    /**
     * Initializes all client connections to forge and to Elasticsearch.
     */
    protected function startUp()
    {
        $this->redmineClient = $this->redmineConnection->getRedmineClient();
        $this->elevatedRedmineClient = $this->redmineConnection->getElevatedRedmineClient();
        $this->elasticClient = $this->elasticConnection->getElasticClient();
        $this->elasticIndex = $this->elasticConnection->getElasticIndex();
    }

    /**
     * Execute the console command.
     *
     * @throws \Exception
     */
    public function fire()
    {
        $mode = $this->argument('mode');
        $this->info('Trying to run mode: "' . $mode . '"');
        $this->startUp();
        $this->totalStart = time();
        switch ($mode) {
            case 'GerritTest':
                $this->getReviewBlock(1, 1);
                break;
            case 'RedmineTest':
                $res = $this->redmineClient->api('issue')->show(80800, ['include' => ['journals']]);
                $this->addDocumentToElastic($res['issue']);
                break;
            case 'RedmineDelta':
                $this->getIssuesSinceLastRun();
                break;
            case 'RedmineFull':
                $fullAmount = 90000;
                $currentSlot = -100;
                $optionalStartOffset = $this->option('startAt');
                if (is_numeric($optionalStartOffset)) {
                    $currentSlot = (int) $optionalStartOffset;
                }
                $perRun = 100;
                while ($currentSlot < $fullAmount) {
                    $result = $this->redmineClient->api('issue')->all(
                        [
                            'limit' => $perRun,
                            'offset' => $currentSlot + $perRun,
                            'project_id' => $this->config->get('app.redmine.projectId'),
                            'status_id' => '*',
                            'sort' => 'updated_on:desc',
                        ]
                    );
                    // break a soon as the API returns no more issues
                    if (count($result['issues']) === 0) {
                        break;
                    }
                    foreach ($result['issues'] as $doc) {
                        $this->getTicketAndJournal($doc);
                    }
                    $currentSlot += $perRun;
                    $this->output->writeln($currentSlot);
                }
                break;
            case 'GerritDelta':
                $result = $this->getReviewBlock(0, 100);
                foreach ($result as $review) {
                    $this->addDocumentToElastic($review, 'review');
                }
                $this->output->writeln('Finished in ' .
                    gmdate('H:i:s', ($this->blockEnd - $this->blockStart)) . '. Added ' . $this->reviewCount .
                    ' reviews with ' . $this->interactionCount . ' interactions.');
                break;
            case 'GerritFull':
                $fullAmount = 30000;
                $currentSlot = 0;
                $optionalStartOffset = $this->option('startAt');
                if (is_numeric($optionalStartOffset)) {
                    $currentSlot = (int) $optionalStartOffset;
                }
                $perRun = 100;
                $this->output->note('Starting Gerrit Run at '.$currentSlot);
                while ($currentSlot <= $fullAmount) {
                    $this->blockStart = time();
                    $this->interactionCount = 0;
                    $this->reviewCount = 0;
                    if ($this->moreIssuesFromGerrit) {
                        $result = $this->getReviewBlock($currentSlot, $perRun);
                        foreach ($result as $review) {
                            $this->addDocumentToElastic($review, 'review');
                        }
                    }
                    $currentSlot += $perRun;
                    $this->blockEnd = time();
                    if ($this->reviewCount > 0) {
                        $this->output->writeln('Finished block ' . $currentSlot . ' in ' .
                            gmdate('H:i:s', ($this->blockEnd - $this->blockStart)) . '. Added ' . $this->reviewCount .
                            ' reviews with ' . $this->interactionCount . ' interactions.');
                    }
                }
                break;
            case 'Users':
                $memberships = $this->elevatedRedmineClient->api('membership')->all('typo3cms-core', ['limit' => 1000]);
                if (isset($memberships['memberships'])) {
                    foreach ($memberships['memberships'] as $userData) {
                        $this->insertUser($userData['user']['id'], $userData['user']['login']);
                    }
                } else {
                    $this->output->writeln('Cannot fetch memberships. Maybe the user may not read them?');
                }
                break;
            case 'SlackTest':
                $doc = $this->redmineClient->api('issue')->show(63618, ['include' => ['journals']]);
                $this->sendSlackMessage($doc['issue']);
                break;
            default:
                $this->error('The provided import-mode has not yet been implemented');
        }
        $this->totalEnd = time();
        $this->output->success('Finished job in ' . gmdate("H:i:s", ($this->totalEnd - $this->totalStart)));
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments() : array
    {
        return [
            ['mode', InputArgument::REQUIRED, 'Import mode; Allowed values are: AddMapping, GerritTest, RedmineTest, RedmineDelta, RedmineFull, GerritDelta, GerritFull, Users'],
            ['startAt', InputOption::VALUE_OPTIONAL, 'In case of using the RedmineFull command, you can optionally define the startingpoint'],
        ];
    }

    /**
     * Get the updates since the last run
     * GET /issues.xml?updated_on=%3E%3D2014-01-02T08:12:32Z.
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    private function getIssuesSinceLastRun()
    {
        //@todo implement getIssuesSinceLastRun
        $config = [
            'limit' => 50,
            'project_id' => $this->config->get('app.redmine.projectId'),
            'status_id' => '*',
            'sort' => 'updated_on:desc',
        ];
        $result = $this->redmineClient->api('issue')->all($config);
        foreach ($result['issues'] as $doc) {
            $this->getTicketAndJournal($doc);
        }
    }

    /**
     * Imports a single ticket.
     * A sleep(1) might be useful here to not hammer forge.
     *
     * @param array $issue
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    private function getTicketAndJournal(array $issue)
    {
        //import journals only if issue status is "Under review" (ID: 8)
        if (isset($issue['status']['id']) && (int) $issue['status']['id'] === 8) {
            $issueWithJournals = $this->redmineClient->api('issue')->show($issue['id'], ['include' => ['journals']]);
            if (is_array($issueWithJournals)) {
                $this->addDocumentToElastic($issueWithJournals['issue']);
            }
        } else {
            $this->addDocumentToElastic($issue);
        }
    }

    /**
     * @param $current
     * @param $perRun
     *
     * @return array
     *
     * @throws \Exception
     */
    protected function getReviewBlock($current, $perRun) : array
    {
        $moreChanges = false;
        $out = [];
        $json = file_get_contents('https://review.typo3.org/changes/?q=project:Packages/TYPO3.CMS&n=' . $perRun .
            '&start=' . $current . '&o=DETAILED_LABELS&o=CURRENT_REVISION&o=ALL_FILES&o=CURRENT_COMMIT&o=MESSAGES&o=DETAILED_ACCOUNTS');
        $data = json_decode(str_replace(")]}'", '', $json), true);
        if (!is_array($data)) {
            $this->line('no array from result');
        } else {
            foreach ($data as $change) {
                $labels = $this->extractLabels($change['labels']);
                if (!array_key_exists('mergeable', $change)) {
                    $change['mergeable'] = false;
                }

                if (!array_key_exists('current_revision', $change)) {
                    continue;
                }

                $patchSetNumber = 0;
                $interaction = [];
                foreach ($change['messages'] as $message) {
                    try {
                        $interaction['interaction_id'] = $message['id'];
                        $interaction['interaction_source'] = 'Gerrit';
                        $interaction['interaction_source_id'] = $change['_number'];
                        $interaction['interacted_on'] = $this->fixDateFormat($message['date'], 'Gerrit');

                        if (!isset($message['_revision_number'])) {
                            $message['_revision_number'] = $patchSetNumber;
                        }

                        // Check if is merge
                        if ($this->checkIfMessageIsMerge($message['message'])) {
                            // set author of interaction
                            $interaction['interaction_type'] = 'Merge';
                            $interaction['account'] =
                                $change['revisions'][$change['current_revision']]['uploader']['username'];
                            $interaction['fullname'] =
                                $change['revisions'][$change['current_revision']]['uploader']['name'];
                            $this->addDocumentToElastic($interaction, 'interactions');
                        } elseif ($this->checkIfMessageIsBackport($message['message'])) {
                            // code intentionally left blank
                        } else {
                            $interaction['account'] = $message['author']['username'];
                            $interaction['fullname'] = $message['author']['name'];
                            // Is patchset, add as event
                            if ($patchSetNumber < $message['_revision_number']) {
                                $patchSetNumber = $message['_revision_number'];
                                $interaction['interaction_type'] = 'Added_Patchset';
                                $this->addDocumentToElastic($interaction, 'interactions');
                            }
                            // Check if message is "just" a new patchset
                            if ($this->checkIfMessageIsComment($message['message'])) {
                                $interaction['interaction_type'] = 'Comment';
                                $this->addDocumentToElastic($interaction, 'interactions');
                            }
                            // Check if message is a code review
                            if ($this->checkIfMessageIsCodeReview($message['message'])) {
                                $interaction['interaction_type'] = 'Code_Review';
                                $this->addDocumentToElastic($interaction, 'interactions');
                            }
                            // Check if message is a verification
                            if ($this->checkIfMessageIsVerification($message['message'])) {
                                $interaction['interaction_type'] = 'Verification';
                                $this->addDocumentToElastic($interaction, 'interactions');
                            }
                            // Check if message is abandoning
                            if ($this->checkIfMessageIsAbandoning($message['message'])) {
                                $interaction['interaction_type'] = 'Abandoned_Patch';
                                $this->addDocumentToElastic($interaction, 'interactions');
                            }
                        }
                    } catch (\Exception $e) {
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
                            var_dump($message);
                            throw new \Exception($e->getMessage());
                        }
                    }
                }

                $author = $change['revisions'][$change['current_revision']]['commit']['author']['name'];
                $filesInPatchset = array_keys($change['revisions'][$change['current_revision']]['files']);

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
                    'reviewtags' => $this->extractReviewTags($change['subject']),
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
                    'owner' => $author ?? 'no one',
                    'files' => $filesInPatchset,
                ];
                if (array_key_exists('_more_changes', $change)) {
                    $moreChanges = true;
                }
            }
        }
        if ($moreChanges === false) {
            $this->moreIssuesFromGerrit = false;
        }
        #$this->output->warning(implode(chr(10), array_keys($this->messages)));
        $this->messages = [];
        return $out;
    }

    /**
     * @param string $message
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function extractReleases($message) : array
    {
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
                throw new \InvalidArgumentException('Release contains space? ' . $message);
            }
            $releaseArray[$key] = $release;
        }

        return $releaseArray;
    }

    /**
     * @param array $labels
     *
     * @throws \Exception
     *
     * @return array
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
                    throw new \InvalidArgumentException('Unknown Label type ' . $labelType);
            }
            if (array_key_exists('all', $subRow)) {
                foreach ($subRow['all'] as $vote) {
                    if ($vote['_account_id'] !== self::BAMBOO_USER_ID) {
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
        $patchTags = [];
        $pattern = '/\[([a-zA-Z0-9]*)\]/';
        preg_match_all($pattern, $message, $matches, PREG_SET_ORDER, 0);
        if (\is_array($matches)) {
            foreach ($matches as $index => $match) {
                $patchTags[] = $match[1];
            }
        }
        return $patchTags;
    }

    /**
     * @param array  $document
     * @param string $type
     *
     * @throws \Exception
     */
    protected function addDocumentToElastic(array $document, $type = 'issue')
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
                $elasticType = new DocType($this->elasticIndex, 'issue');
                try {
                    $elasticType->getDocument($document['id']);
                } catch (NotFoundException $e) {
                    $this->sendSlackMessage($document);
                }
                break;
            case 'review':
                $elasticType = new DocType($this->elasticIndex, 'review');
                break;
            case 'user':
                $elasticType = new DocType($this->elasticIndex, 'user');
                break;
            case 'interactions':
                $elasticType = new DocType($this->elasticIndex, 'interactions');
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
            switch ($type) {
                case 'review':
                    $this->reviewCount++;
                    break;
                case 'issue':
                    $this->output->write('F');
                    break;
                case 'interactions':
                    $this->interactionCount++;
                    break;
                default:
                    $this->output->write('?');
            }

        }
    }

    /**
     * @param string $dateTimeString
     * @param string $source
     *
     * @return string
     * @throws \InvalidArgumentException
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
                throw new \InvalidArgumentException('Value of $source has to be either "Redmine" or "Gerrit"');

        }
        $dateTime = new \DateTime($dateTimeString);
        if (!$dateTime) {
            throw new \InvalidArgumentException('Date format for "' . $dateTimeString . '" could not be parsed');
        }
        return $dateTime->format('Y/m/d H:i:s');
    }

    /**
     * @param int    $userId
     * @param string $username
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    protected function insertUser($userId = 0, $username = '')
    {
        $userData = $this->redmineClient->api('user')->show($userId);
        $compiledData = [
            'id' => $userId,
            'username' => $username,
            'email' => $userData['user']['mail'] ?? null,
            'fullname' => $userData['user']['firstname'] . ' ' . $userData['user']['lastname'],
        ];
        $this->addDocumentToElastic($compiledData, 'user');
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
     * @param array $issue
     *
     * @return bool
     */
    protected function sendSlackMessage(array $issue) : bool
    {
        $this->output->note('Added issue  ' . $issue['id']);
        $slackConfig['Url'] = $this->config->get('app.slack.webhook_url');
        if ($slackConfig['Url'] === 'TEAMURL') {
            return true;
        }
        $color = 'good';
        if ($issue['tracker']['name'] === 'Bug') {
            $color = 'danger';
        }
        $jsonString = json_encode([
            'channel' => '#typo3-cms-issues',
            'username' => 'Forger found a new issue',
            'icon_url' => 'https://typo3.slack.com/services/B02KBL9GG',
            'attachments' => [
                [
                    'title' => $issue['subject'],
                    'title_link' => 'https://forge.typo3.org/issues/' . $issue['id'],
                    'text' => $issue['description'],
                    'fallback' => $issue['subject'],
                    'author_name' => $issue['author']['name'],
                    'color' => $color,
                    'fields' => [
                        [
                            'title' => 'Status',
                            'value' => $issue['status']['name'],
                            'short' => true,
                        ],
                        [
                            'title' => 'Tracker',
                            'value' => $issue['tracker']['name'],
                            'short' => true,
                        ],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT);
        $client = new Client();
        $client->request('POST', $slackConfig['Url'], ['body' => $jsonString]);

        return true;
    }

    private function checkIfMessageIsComment(string $message)
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
        if ($isCodeReview || $isVerification || $isPatchset ||$isCommitMessageChange || $this->checkIfMessageIsBackport($message) ||$this->checkIfMessageIsMerge($message)) {
            return false;
        }
        return true;
    }

    private function checkIfMessageIsCodeReview(string $message)
    {
        $pattern = '^Patch\sSet\s\d{1,4}:\s.*(Code-Review)(-|\+)(1|2)';
        return $this->checkRegex($message, $pattern);
    }

    private function checkIfMessageIsVerification(string $message)
    {
        $pattern = '^Patch\sSet\s\d{1,4}:\s.*(Verified)(-|\+)(1|2)';
        return $this->checkRegex($message, $pattern);
    }

    private function checkIfMessageIsAbandoning(string $message)
    {
        $pattern = '^Abandoned';
        return $this->checkRegex($message, $pattern);
    }

    private function checkIfMessageIsMerge(string $message)
    {
        $pattern = 'Change has been successfully cherry-picked as \b[0-9a-f]{40}\b';
        return $this->checkRegex($message, $pattern);
    }

    private function checkIfMessageIsBackport(string $message)
    {
        $pattern = 'This patchset was cherry picked to branch.*? as commit \b[0-9a-f]{40}\b';
        return $this->checkRegex($message, $pattern);
    }

    private function checkRegex(string $string, string $pattern, $modifier = 'm')
    {
        preg_match('/' . $pattern . '/'. $modifier, $string, $matches);
        if (isset($matches[0])) {
            return true;
        }
        return false;
    }

    protected function getOptions()
    {
        return [
            ['startAt', null, InputOption::VALUE_OPTIONAL, 'When to start', null]
        ];
    }
}
