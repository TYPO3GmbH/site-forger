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
 * Version 6 of the changes: the accounts that voted on the current patch set, read from the stored votes.
 */
final readonly class ChangeVoters extends SchemaStep
{
    public function index(): string
    {
        return Indices::REVIEW;
    }

    public function from(): int
    {
        return 5;
    }

    public function fields(): array
    {
        return ['votes'];
    }

    public function derive(array $document): array
    {
        $votes = array_values(array_filter((array) ($document['votes'] ?? []), is_array(...)));

        return ['voters' => GerritChangeImporter::voters($votes)];
    }
}
