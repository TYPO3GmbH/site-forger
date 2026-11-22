<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

/**
 * Which version of StreamEvents wrote the events of an issue or a change. The documents of the issue and the
 * review index keep it in the field "stream_schema". IssueActivity and ChangeActivity write the events of the
 * documents without the current version, so the stream fills without a step by hand.
 *
 * Raise VERSION when StreamEvents derives other events: the backfill then writes all of them again, the documents
 * changed last first.
 */
final class StreamSchema
{
    public const string FIELD = 'stream_schema';
    public const int VERSION = 7;

    /**
     * The documents of the issue or the review index whose events an older version wrote, or none at all.
     *
     * @return array<string, mixed>
     */
    public static function outdated(): array
    {
        return ['bool' => ['must_not' => [['term' => [self::FIELD => self::VERSION]]]]];
    }

    /**
     * The document with the mark that its events are written.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public static function marked(array $document): array
    {
        return [...$document, self::FIELD => self::VERSION];
    }
}
