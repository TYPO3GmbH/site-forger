<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Elastic\Indices;
use App\Import\Backfill\BackfillStep;

/**
 * Writes the events of the changes the index has from before the activity stream, or from an older StreamSchema.
 * The imports write the events of the changes they load. This step takes the rest.
 */
final readonly class ChangeActivity implements BackfillStep
{
    public function __construct(
        private StreamWriter $writer,
    ) {
    }

    public function index(): string
    {
        return Indices::REVIEW;
    }

    public function name(): string
    {
        return 'activity of the changes';
    }

    public function outdated(): array
    {
        return StreamSchema::outdated();
    }

    /**
     * What StreamEvents reads of a document. The rest, like the files and comments of a change, is large.
     */
    public function fields(): array
    {
        return ['id', 'subject', 'branch', 'created_on', 'owner_id', 'messages'];
    }

    public function lift(array $documents): array
    {
        $this->writer->changes($documents);

        return array_fill_keys(
            array_map(static fn (array $document): int => (int) $document['id'], $documents),
            [StreamSchema::FIELD => StreamSchema::VERSION],
        );
    }

    public function writesOutside(): bool
    {
        return true;
    }
}
