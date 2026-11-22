<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import\Backfill;

use App\Elastic\Indices;
use App\Import\GerritChangeImporter;

/**
 * Version 5 of the changes: the day a change closed, read from its stored review log. The log of a merge has the
 * time of Gerrit's submit, for changes before 2016 up to three seconds later. The step leaves "submitted" empty:
 * only Gerrit tells it, when the import loads the change again.
 */
final readonly class ChangeClosedOn extends SchemaStep
{
    public function index(): string
    {
        return Indices::REVIEW;
    }

    public function from(): int
    {
        return 4;
    }

    public function fields(): array
    {
        return ['reviewstatus', 'messages'];
    }

    public function derive(array $document): array
    {
        $log = array_values(array_filter((array) ($document['messages'] ?? []), is_array(...)));

        return ['closed_on' => GerritChangeImporter::closedOn((string) ($document['reviewstatus'] ?? ''), null, $log)];
    }
}
