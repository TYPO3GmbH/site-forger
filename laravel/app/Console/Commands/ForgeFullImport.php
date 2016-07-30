<?php namespace App\Console\Commands;

use App\Elastic\Connection;
use App\Redmine\Connection as RedmineConnection;
use Elastica\Document;
use Elastica\Type;
use Elastica\Type as DocType;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Yaml\Yaml;

class ForgeFullImport extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'Forger:Import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Central data import functions';

    /**
     * @var Repository
     */
    protected $config;

    /**
     * @var \Redmine\Client
     */
    protected $redmineClient;

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

    /**
     * ForgeFullImport constructor.
     *
     * @param Repository $config
     * @param Connection                    $elasticConnection
     * @param RedmineConnection             $redmineConnection
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
     * Initializes all client connections to forge and to Elasticsearch
     */
    protected function startUp()
    {
        $this->redmineClient = $this->redmineConnection->getRedmineClient();
        $this->elasticClient = $this->elasticConnection->getElasticClient();
        $this->elasticIndex = $this->elasticConnection->getElasticIndex();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Exception
     */
    public function fire()
    {
        $mode = $this->argument('mode');
        $this->info('Trying to run mode: "' . $mode . '"');
        $this->startUp();
        switch ($mode) {
            case 'AddMapping':
                $this->addMapping('issue');
                $this->addMapping('user');
                $this->addMapping('review');
                break;
            case 'GerritTest':
                $res = $this->getReviewBlock(1, 1);
                break;
            case 'RedmineTest':
                $res = $this->redmineClient->api('issue')->show(63618, array('include' => array('journals')));
                $this->addDocumentToElastic($res['issue']);
                break;
            case 'RedmineDelta':
                $this->getIssuesSinceLastRun();
                break;
            case 'RedmineFull':
                $fullAmount = 50000;
                $currentSlot = -100;
                $perRun = 100;
                while ($currentSlot < $fullAmount) {
                    $result = $this->redmineClient->api('issue')->all(
                        [
                            'limit' => $perRun,
                            'offset' => $currentSlot + $perRun,
                            'project_id' => 27,
                            'status_id' => '*',
                            'sort' => 'updated_on:desc'
                        ]
                    );
                    foreach ($result['issues'] as $doc) {
                        $this->getTicketAndJournal($doc['id']);
                    }
                    $currentSlot += $perRun;
                    $this->output->writeln($currentSlot);
                }
                break;
            case 'GerritDelta':
                $result = $this->getReviewBlock(0, 35);
                foreach ($result as $review) {
                    $this->addDocumentToElastic($review, 'review');
                }
                break;
            case 'GerritFull':
                $fullAmount = 30000;
                $currentSlot = 0;
                $perRun = 100;
                while ($currentSlot <= $fullAmount) {
                    $this->info('Importing Gerrit Changes starting at ' . $currentSlot);
                    if ($this->moreIssuesFromGerrit) {
                        $result = $this->getReviewBlock($currentSlot, $perRun);
                        foreach ($result as $review) {
                            $this->addDocumentToElastic($review, 'review');
                        }
                    }
                    $currentSlot += $perRun;
                    $this->output->writeln('');
                }
                break;
            default:
                $this->error('The provided import-mode has not yet been implemented');
        }
    }

    /**
     * Loads ElasticSearch document type mappings and applies them
     *
     * @param string $mappingName
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    protected function addMapping(string $mappingName)
    {
        $parser = new Yaml();
        $yamlString = file_get_contents(config_path() . '/Mappings/' . $mappingName . '.yaml');
        $mapping = $parser::parse($yamlString);
        $typeMapping = $mapping['T3G']['Forger']['Elasticsearch']['Mapping'];
        foreach ($typeMapping as $index => $item) {
            $docTypeToMap = $this->elasticIndex->getType($index);
            try {
                $this->info('Try to apply mapping for "' . $index . '"');
                $docTypeToMap->setMapping($item);
            } catch (\Exception $e) {
                $this->error($e->getMessage());
            }
            $this->info('Added mapping for "' . $index . '"');
        }
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['mode', InputArgument::REQUIRED, 'Potential modes'],
        ];
    }

    /**
     * Get the updates since the last run
     * GET /issues.xml?updated_on=%3E%3D2014-01-02T08:12:32Z
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    private function getIssuesSinceLastRun()
    {
        //@todo implement getIssuesSinceLastRun
        $config = [
            'limit' => 50,
            'project_id' => 27,
            'status_id' => '*',
            'sort' => 'updated_on:desc'
        ];
        $result = $this->redmineClient->api('issue')->all($config);
        foreach ($result['issues'] as $doc) {
            $this->getTicketAndJournal($doc['id']);
        }
    }

    /**
     * Imports a single ticket.
     * A sleep(1) might be useful here to not hammer forge
     *
     * @param int $issueId
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    private function getTicketAndJournal($issueId)
    {
        $res = $this->redmineClient->api('issue')->show($issueId, array('include' => array('journals')));
        if (is_array($res)) {
            $this->addDocumentToElastic($res['issue']);
        }
    }

    /**
     * @param $current
     * @param $perRun
     *
     * @return array
     * @throws \Exception
     */
    protected function getReviewBlock($current, $perRun)
    {
        $moreChanges = false;
        $out = [];
        $json = file_get_contents('https://review.typo3.org/changes/?q=project:Packages/TYPO3.CMS&n=' . $perRun .
            '&start=' . $current . '&o=DETAILED_LABELS&o=CURRENT_REVISION&o=ALL_FILES&o=CURRENT_COMMIT');
        $data = json_decode(str_replace(")]}'", '', $json), true);
        foreach ($data as $change) {
            $labels = $this->extractLabels($change['labels']);
            if (!array_key_exists('mergeable', $change)) {
                $change['mergeable'] = false;
            }

            if (!array_key_exists('current_revision', $change)) {
                continue;
            }

            $author = $change['revisions'][$change['current_revision']]['commit']['author']['name'];

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
                'positive_reviews' => $labels['positive_reviews'],
                'negative_reviews' => $labels['negative_reviews'],
                'positive_verifications' => $labels['positive_verifications'],
                'negative_verifications' => $labels['negative_verifications'],
                'patchsets' => $change['revisions'][$change['current_revision']]['_number'],
                'created_on' => $this->fixDateFormat(str_replace('-', '/', $change['created'])),
                'updated_on' => $this->fixDateFormat(str_replace('-', '/', $change['updated'])),
                'releases' => $this->extractReleases(
                    $change['revisions'][$change['current_revision']]['commit']['message']
                ),
                'owner' => isset($author) ? $author : 'no one',
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
     * @throws \Exception
     * @return array
     */
    protected function extractReleases($message)
    {
        $pattern = '/Releases:\s*(.*)/';
        preg_match($pattern, $message, $matches);
        if (!isset($matches[1])) {
            return ['omitted'];
        }
        /**
         * fix Stuff
         */
        if (strstr($matches[1], 'master.')) {
            $matches[1] = str_replace('master.', 'master,', $matches[1]);
        }
        if (strstr($matches[1], '4.7.')) {
            $matches[1] = str_replace('4.7.', '4.7,', $matches[1]);
        }
        if (strstr($matches[1], '4.5 LTS')) {
            $matches[1] = str_replace('4.5 LTS', '4.5', $matches[1]);
        }
        $releaseArray = $this->trimExplode(',', $matches[1]);
        foreach ($releaseArray as $key => $release) {
            if (strstr($release, ' ')) {
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
     * @return array
     */
    protected function extractLabels(array $labels)
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
        return $out;
    }

    /**
     * @param array  $document
     * @param string $type
     *
     * @throws \Exception
     */
    protected function addDocumentToElastic(array $document, $type = 'issue')
    {
        switch ($type) {
            case 'issue':
                if (isset($document['custom_fields'])) {
                    foreach ($document['custom_fields'] as $customField) {
                        switch ($customField['id']) {
                            case 4:
                                $document['typo3_version'] =
                                    (isset($customField['value']) ? $customField['value'] : '-');
                                break;
                            case 5:
                                $document['php_version'] = (isset($customField['value']) ? $customField['value'] : '-');
                                break;
                            case 8:
                                $document['complexity'] = (isset($customField['value']) ? $customField['value'] : '-');
                                break;
                            case 15:
                                $document['isregression'] = (isset($customField['value']) ? true : false);
                                break;
                            case 18:
                                $document['focus']['name'] =
                                    (isset($customField['value']) ? $customField['value'] : '-');
                                break;
                            default:
                        }
                    }
                }
                $document['updated_on'] = $this->fixDateFormat($document['updated_on']);
                $document['created_on'] = $this->fixDateFormat($document['created_on']);
                $type = new DocType($this->elasticIndex, 'issue');
                try {
                    $type->getDocument($document['id']);
                } catch (\Exception $e) {
                    #$message = new Message();
                    #$message->sendMessage($document);
                }
                break;
            case 'review':
                $type = new DocType($this->elasticIndex, 'review');
                break;
            case 'user':
                $type = new DocType($this->elasticIndex, 'user');
                break;
            default:
        }
        $doc = new Document($document['id'], $document);
        $type->addDocument($doc);
        $this->output->write('+');
    }

    /**
     * @param string $string
     *
     * @return string
     */
    protected function fixDateFormat($string)
    {
        $pattern =
            '/([0-9]{4}\/[0-9]{2}\/[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}|[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})/';
        preg_match($pattern, $string, $matches);
        if (isset($matches[1])) {
            return $matches[1];
        } else {
            return 'broken';
        }
    }

    /**
     * Imports users from Redmine
     */
    public function usersCommand()
    {
        $this->startUp('elevated');
        $memberships = $this->redmineClient->api('membership')->all('typo3cms-core', array('limit' => 1000));
        if (isset($memberships['memberships'])) {
            foreach ($memberships['memberships'] as $userData) {
                $this->insertUser($userData['user']['id'], $userData['user']['login']);
            }
        } else {
            $this->output->writeln('Cannot fetch memberships. Maybe the user may not read them?');
        }
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
            'email' => (isset($userData['user']['mail']) ? $userData['user']['mail'] : null),
            'fullname' => $userData['user']['firstname'] . ' ' . $userData['user']['lastname']
        ];
        $this->addDocumentToElastic($compiledData, 'user');
    }

    /**
     * Explodes a string and trims all values for whitespace in the ends.
     * If $onlyNonEmptyValues is set, then all blank ('') values are removed.
     *
     * @param	string	$delim	Delimiter string to explode with
     * @param	string	$string	The string to explode
     * @param	boolean	$removeEmptyValues	If set, all empty values will be removed in output

     * @return	array		Exploded values
     */
    public function trimExplode($delim, $string, $removeEmptyValues = FALSE) {
        $explodedValues = explode($delim, $string);

        $result = array_map('trim', $explodedValues);

        if ($removeEmptyValues) {
            $temp = array();
            foreach ($result as $value) {
                if ($value !== '') {
                    $temp[] = $value;
                }
            }
            $result = $temp;
        }

        return $result;
    }
}