<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import\Backfill;

use App\Import\ImportSchema;

/**
 * One step of ImportSchema that the stored data can take without Forge or Gerrit: it derives the fields of the next
 * version from a document of the version before. Add a step when a new version only needs data the index already
 * has. Versions without a step wait for the import.
 */
abstract readonly class SchemaStep implements BackfillStep
{
    /**
     * The version of ImportSchema the step reads. It writes the next one.
     */
    abstract public function from(): int;

    /**
     * The new fields with their values.
     *
     * @param array<string, mixed> $document the fields of fields()
     *
     * @return array<string, mixed>
     */
    abstract public function derive(array $document): array;

    public function name(): string
    {
        return sprintf('%s version %d to %d', $this->index(), $this->from(), $this->from() + 1);
    }

    public function outdated(): array
    {
        return ImportSchema::writtenBy($this->from());
    }

    public function lift(array $documents): array
    {
        $fields = [];
        foreach ($documents as $document) {
            $fields[(int) $document['id']] = [...$this->derive($document), ImportSchema::FIELD => $this->from() + 1];
        }

        return $fields;
    }

    public function writesOutside(): bool
    {
        return false;
    }
}
