<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\Http\ApiClient;
use App\Http\ApiFailure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lists of the issues Forge has for the index: of the project itself, not of its subprojects, not private, in any
 * status. Exactly the issues the index holds (App\Import\ProjectIssues::belongsToProject()), so their number can be
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
        private ApiClient $forge,
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

        $highest = $answer['issues'][0]['id'] ?? null;

        return ['count' => $answer['total_count'], 'highest' => null === $highest ? null : (int) $highest];
    }

    /**
     * A page of all issues of the project, the newest first. With a filter of "updated_on" the oldest come first.
     *
     * @param string|null $updatedOn see ImportWatermark::redmineFilter()
     *
     * @return array{issues: list<array<string, mixed>>, total_count: int}
     *
     * @throws ApiFailure
     */
    public function page(int $offset, ?string $updatedOn = null): array
    {
        return self::issueList($this->forge->object('/issues.json', [
            'limit' => self::PAGE_SIZE,
            'offset' => $offset,
            'project_id' => $this->projectId,
            'status_id' => '*',
            'sort' => null === $updatedOn ? 'updated_on:desc' : 'updated_on',
            'include' => 'relations',
            'updated_on' => $updatedOn,
        ], 'a list of issues'));
    }

    /**
     * The issues with a number in the range, at most PAGE_SIZE, the lowest numbers first.
     *
     * @return list<array<string, mixed>>
     */
    public function range(int $from, int $to): array
    {
        return $this->get(self::path($this->projectId, $from, $to, null, self::PAGE_SIZE, 'id'))['issues'];
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
            $path = self::path($this->projectId, null, null, $chunk, self::PAGE_SIZE, 'id');
            array_push($issues, ...$this->get($path)['issues']);
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
     * @return array{issues: list<array<string, mixed>>, total_count: int}
     *
     * @throws ApiFailure Forge could not be asked
     */
    private function get(string $path): array
    {
        $answer = $this->forge->object($path, [], 'a list of issues');
        if (!array_key_exists('total_count', $answer)) {
            throw ApiFailure::unexpected($this->forge->name, 'a list of issues', json_encode($answer));
        }

        return self::issueList($answer);
    }

    /**
     * @param array<string, mixed> $answer a list of issues as Forge sends it
     *
     * @return array{issues: list<array<string, mixed>>, total_count: int}
     */
    private static function issueList(array $answer): array
    {
        $issues = [];
        foreach ((array) ($answer['issues'] ?? []) as $issue) {
            if (is_array($issue)) {
                $issues[] = $issue;
            }
        }

        return ['issues' => $issues, 'total_count' => (int) ($answer['total_count'] ?? 0)];
    }
}
