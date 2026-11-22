<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Document\StreamEvent;

/**
 * Where the next page of the activity stream starts: after this event, in the order of StreamQuery, the newest
 * first. Elasticsearch pages with it beyond the 10000 events "offset" reaches, see "search_after". As text it is
 * "<milliseconds>-<id>": the time of the event and its id, the values the stream is sorted by.
 */
final readonly class StreamCursor
{
    private function __construct(
        public int $milliseconds,
        public string $id,
    ) {
    }

    /**
     * The cursor after the event, null for an event without a valid date.
     */
    public static function after(StreamEvent $event): ?self
    {
        $date = \DateTimeImmutable::createFromFormat('!Y/m/d H:i:s', $event->date, new \DateTimeZone('UTC'));
        if (false === $date || '' === $event->id) {
            return null;
        }

        return new self($date->getTimestamp() * 1000, $event->id);
    }

    /**
     * The cursor of the text value(), null for another text.
     */
    public static function fromValue(string $value): ?self
    {
        if (1 !== preg_match('/^(\d{1,15})-(.{1,255})$/s', $value, $matches)) {
            return null;
        }

        return new self((int) $matches[1], $matches[2]);
    }

    public function value(): string
    {
        return $this->milliseconds . '-' . $this->id;
    }

    /**
     * The values of "search_after", in the order of the sort of StreamQuery.
     *
     * @return array{int, string}
     */
    public function searchAfter(): array
    {
        return [$this->milliseconds, $this->id];
    }
}
