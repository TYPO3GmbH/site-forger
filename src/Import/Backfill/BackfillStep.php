<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import\Backfill;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A piece of work on the stored issues or changes that needs neither Forge nor Gerrit: it reads documents of an
 * older version and lifts them to the current one. SchemaBackfill runs all steps. Add a step when stored data is
 * enough for a migration. SchemaStep is the base for a step of ImportSchema.
 */
#[AutoconfigureTag(self::TAG)]
interface BackfillStep
{
    public const string TAG = 'app.backfill_step';

    /**
     * @return 'issue'|'review'
     */
    public function index(): string;

    /**
     * What the step lifts in a few words, for the output of a run: "review version 4 to 5".
     */
    public function name(): string;

    /**
     * The query of the documents the step still has to lift. A lifted document must not match any more.
     *
     * @return array<string, mixed>
     */
    public function outdated(): array;

    /**
     * The fields of a document lift() reads. Keep them few: the backfill loads no others.
     *
     * @return list<non-empty-string>
     */
    public function fields(): array;

    /**
     * Does the work for a batch of documents and returns the fields to write for each, by its id. The backfill
     * writes these fields only and leaves the rest of a document.
     *
     * @param list<array<string, mixed>> $documents the fields of fields() and "id"
     *
     * @return array<int, array<string, mixed>>
     */
    public function lift(array $documents): array;

    /**
     * Whether lift() writes outside the document, like the events of the activity stream. An import may write a
     * document while the step works on it. The backfill then drops its own update of the document. With true, it
     * also runs lift() again for the new document, so what the step wrote outside fits the document.
     */
    public function writesOutside(): bool;
}
