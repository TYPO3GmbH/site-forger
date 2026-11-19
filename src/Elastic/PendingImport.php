<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use App\Enum\ReviewStatusEnum;
use App\Enum\SystemCheckEnum;

/**
 * Data the import adds since a change of what it imports, and which documents do not have it yet.
 *
 * A full import skips documents that did not change in the source system, so documents imported before
 * get the new data with "--force" only. Each check counts the documents that still wait for it.
 * Add a check with a change of the import, remove it once every environment imported again.
 */
final class PendingImport
{
    /**
     * The documents wait while some match the query.
     */
    public const string MATCHES_WAIT = 'matches wait';
    /**
     * The documents wait while none matches the query, though the index has documents.
     */
    public const string NONE_WAIT = 'none waits';

    /**
     * @return list<array{label: string, description: string, index: string, query: array<string, mixed>, kind: string, command: string}>
     */
    public static function checks(): array
    {
        return [
            [
                'label' => 'Assignees without an avatar',
                'description' => 'Issues imported before the import kept the email of the assignee. Their assignee shows without an avatar.',
                'index' => 'issue',
                'query' => ['bool' => [
                    'filter' => [['exists' => ['field' => 'assigned_to.name']]],
                    // An assigned group gets an empty email, which counts as there
                    'must_not' => [['exists' => ['field' => 'assigned_to.email']]],
                ]],
                'kind' => self::MATCHES_WAIT,
                'command' => 'app:import --mode=forgeFull --force',
            ],
            [
                'label' => '"[POC]" changes not in work in progress',
                'description' => 'Open changes with "[POC]" in the subject, imported before it marked a change as work in progress. They show in the lists of changes ready for review.',
                'index' => 'review',
                'query' => ['bool' => [
                    'filter' => [['term' => ['reviewtags' => 'POC']], ['term' => ['work_in_progress' => false]]],
                    'must_not' => [['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]]],
                ]],
                'kind' => self::MATCHES_WAIT,
                'command' => 'app:import --mode=reviewFull --force',
            ],
            [
                'label' => 'Breaking changes tagged "!!!"',
                'description' => 'The import before did not read "[!!!]" from the subject, and the index cannot tell which changes have it there. So this only shows whether the import that reads it ran: once one change has the tag, it is OK, though changes imported before may still miss it.',
                'index' => 'review',
                'query' => ['term' => ['reviewtags' => ReviewSubject::BREAKING]],
                'kind' => self::NONE_WAIT,
                'command' => 'app:import --mode=reviewFull --force',
            ],
            [
                'label' => 'Votes without the single votes',
                'description' => 'Changes imported before the import kept the lowest and highest single vote of each label. Their votes show as +1 or -1 at most, also a +2 or -2 of a merger.',
                'index' => 'review',
                'query' => ['bool' => ['must_not' => [['exists' => ['field' => 'highest_review_vote']]]]],
                'kind' => self::MATCHES_WAIT,
                'command' => 'app:import --mode=reviewFull --force',
            ],
        ];
    }

    /**
     * @param int $matches   the documents matching the query of the check
     * @param int $documents all documents of the index
     */
    public static function result(string $kind, int $matches, int $documents): SystemCheckEnum
    {
        return match ($kind) {
            self::MATCHES_WAIT => 0 === $matches ? SystemCheckEnum::OK : SystemCheckEnum::ATTENTION,
            self::NONE_WAIT => 0 === $matches && $documents > 0 ? SystemCheckEnum::ATTENTION : SystemCheckEnum::OK,
            default => throw new \InvalidArgumentException(sprintf('Unknown kind of check "%s".', $kind)),
        };
    }
}
