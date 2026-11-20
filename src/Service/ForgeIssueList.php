<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Service;

use Redmine\Http\HttpFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lists of the issues Forge has for the index: of the project itself, not of its subprojects, not private, in any
 * status. Exactly the issues the index holds (App\Elastic\ProjectIssues::belongsToProject()), so their number can be
 * compared with the index. Forge counts the subprojects and the private issues the token sees otherwise.
 *
 * The filters are those of the issue list of Redmine ("f[]", "op[...]", "v[...][]"), which the API client cannot
 * build: the paths are built here.
 */
final readonly class ForgeIssueList
{
    /**
     * The most issues Forge lists in one answer.
     */
    public const int PAGE_SIZE = 100;

    public function __construct(
        private ForgeIssueImporter $issueImporter,
        #[Autowire('%redmine_project_id%')]
        private int $projectId,
    ) {
    }

    /**
     * How many issues Forge has with a number in the range, and the highest number among them.
     *
     * @return array{count: int, highest: int|null}
     */
    public function count(?int $from = null, ?int $to = null): array
    {
        $answer = $this->get(self::path($this->projectId, $from, $to, null, 1, 'id:desc'));

        return ['count' => (int) ($answer['total_count'] ?? 0), 'highest' => isset($answer['issues'][0]['id']) ? (int) $answer['issues'][0]['id'] : null];
    }

    /**
     * The issues with a number in the range, at most PAGE_SIZE, the lowest numbers first.
     *
     * @return list<array<string, mixed>>
     */
    public function range(int $from, int $to): array
    {
        return $this->get(self::path($this->projectId, $from, $to, null, self::PAGE_SIZE, 'id'))['issues'] ?? [];
    }

    /**
     * The issues of the numbers Forge still has for the index, in batches: those missing are deleted, private now,
     * or in another project.
     *
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function byIds(array $ids): array
    {
        $issues = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::PAGE_SIZE) as $chunk) {
            array_push($issues, ...$this->get(self::path($this->projectId, null, null, $chunk, self::PAGE_SIZE, 'id'))['issues'] ?? []);
        }

        return $issues;
    }

    /**
     * @param list<int>|null $ids
     */
    public static function path(int $projectId, ?int $from, ?int $to, ?array $ids, int $limit, string $sort): string
    {
        $filters = [
            'set_filter=1',
            'f[]=status_id', 'op[status_id]=' . rawurlencode('*'),
            'f[]=subproject_id', 'op[subproject_id]=' . rawurlencode('!*'),
            'f[]=is_private', 'op[is_private]=' . rawurlencode('='), 'v[is_private][]=0',
        ];
        if (null !== $ids) {
            $filters = [...$filters, 'f[]=issue_id', 'op[issue_id]=' . rawurlencode('='), 'v[issue_id][]=' . implode(',', array_map(intval(...), $ids))];
        } elseif (null !== $from && null !== $to) {
            $filters = [...$filters, 'f[]=issue_id', 'op[issue_id]=' . rawurlencode('><'), 'v[issue_id][]=' . $from, 'v[issue_id][]=' . $to];
        }

        // With the relations, as the import takes them, see ForgeIssueImporter::document()
        return sprintf('/issues.json?project_id=%d&%s&sort=%s&limit=%d&include=relations', $projectId, implode('&', $filters), rawurlencode($sort), $limit);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException Forge could not be asked
     */
    private function get(string $path): array
    {
        $content = $this->issueImporter->request(HttpFactory::makeJsonRequest('GET', $path))->getContent();
        $answer = json_decode($content, true);
        if (!is_array($answer) || !array_key_exists('total_count', $answer)) {
            throw new \RuntimeException(sprintf('Forge answered without a list of issues: %s', mb_substr($content, 0, 200)));
        }

        return $answer;
    }
}
