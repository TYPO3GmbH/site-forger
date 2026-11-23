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
use App\Import\ForgeIssueImporter;

/**
 * Version 6 of the issues: the stored tags split at spaces too.
 */
final readonly class IssueTags extends SchemaStep
{
    public function index(): string
    {
        return Indices::ISSUE;
    }

    public function from(): int
    {
        return 5;
    }

    public function fields(): array
    {
        return ['tags'];
    }

    public function derive(array $document): array
    {
        $tags = array_map(strval(...), (array) ($document['tags'] ?? []));

        return ['tags' => ForgeIssueImporter::tags(implode(',', $tags))];
    }
}
