<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Document\Change;
use App\Document\Issue;
use App\Enum\StreamSourceEnum;
use App\Repository\StreamRepository;

/**
 * Writes the events of the activity stream for the documents of the issue and the review index. The imports call
 * it for every issue and change they write, IssueActivity and ChangeActivity for the documents written before.
 *
 * Not final: the tests stub it, they have no Elasticsearch.
 */
readonly class StreamWriter
{
    public function __construct(
        private StreamRepository $stream,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $documents of the issue index
     */
    public function issues(array $documents): void
    {
        $issues = array_map(Issue::fromDocument(...), $documents);
        $this->stream->replace(
            StreamSourceEnum::FORGE,
            array_map(static fn (Issue $issue): int => $issue->id, $issues),
            array_merge(...array_map(StreamEvents::ofIssue(...), $issues)),
        );
    }

    /**
     * @param list<array<string, mixed>> $documents of the review index
     */
    public function changes(array $documents): void
    {
        $changes = array_map(Change::fromDocument(...), $documents);
        $this->stream->replace(
            StreamSourceEnum::GERRIT,
            array_map(static fn (Change $change): int => $change->id, $changes),
            array_merge(...array_map(StreamEvents::ofChange(...), $changes)),
        );
    }
}
