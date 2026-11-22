<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\Enum\StreamKindEnum;
use App\Enum\StreamSourceEnum;
use App\People\Person;

/**
 * An event of the activity stream, a document of the stream index: who did what on which issue or change, and when.
 *
 * It holds no text of a comment and no email: the stream is a list, the comments stay on the detail pages.
 */
final readonly class StreamEvent
{
    /**
     * @param string       $date        in the format of the index, "Y/m/d H:i:s" in UTC
     * @param list<string> $summary     what changed in a few words, like "Status: New → Resolved"
     * @param int          $openDelta   1 when it opens the issue or the change, -1 when it closes it, else 0
     * @param int|null     $duration    seconds since the issue or the change began, for the events that end a wait:
     *                                  an issue closed, a change merged, the first review of a change
     * @param bool         $firstReview the first vote or comment on the change by somebody else than its owner
     */
    public function __construct(
        public string $id,
        public StreamSourceEnum $source,
        public StreamKindEnum $kind,
        public int $number,
        public string $title,
        public string $date,
        public ?Person $author = null,
        public bool $bot = false,
        public array $summary = [],
        public ?int $patchSet = null,
        public ?string $branch = null,
        public int $openDelta = 0,
        public ?int $duration = null,
        public bool $firstReview = false,
    ) {
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromDocument(array $document): self
    {
        $author = is_array($document['author'] ?? null) ? $document['author'] : [];
        $name = trim((string) ($author['name'] ?? ''));

        return new self(
            id: (string) ($document['id'] ?? ''),
            source: StreamSourceEnum::tryFrom((string) ($document['source'] ?? '')) ?? StreamSourceEnum::FORGE,
            kind: StreamKindEnum::tryFrom((string) ($document['kind'] ?? '')) ?? StreamKindEnum::CHANGED,
            number: (int) ($document['number'] ?? 0),
            title: (string) ($document['title'] ?? ''),
            date: (string) ($document['date'] ?? ''),
            author: '' === $name ? null : new Person(
                $name,
                forgeId: isset($author['forge_id']) ? (int) $author['forge_id'] : null,
                gerritId: isset($author['gerrit_id']) ? (int) $author['gerrit_id'] : null,
                username: isset($author['username']) ? (string) $author['username'] : null,
            ),
            bot: true === ($document['bot'] ?? false),
            summary: array_values(array_map(strval(...), (array) ($document['summary'] ?? []))),
            patchSet: isset($document['patch_set']) ? (int) $document['patch_set'] : null,
            branch: isset($document['branch']) ? (string) $document['branch'] : null,
            openDelta: (int) ($document['open_delta'] ?? 0),
            duration: isset($document['duration']) ? (int) $document['duration'] : null,
            firstReview: true === ($document['first_review'] ?? false),
        );
    }

    /**
     * The document of the stream index. The author without the email, see AGENTS.md.
     *
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source->value,
            'kind' => $this->kind->value,
            'number' => $this->number,
            'title' => $this->title,
            'date' => $this->date,
            'author' => null === $this->author ? null : [
                'name' => $this->author->name,
                'username' => $this->author->username,
                'forge_id' => $this->author->forgeId,
                'gerrit_id' => $this->author->gerritId,
            ],
            'bot' => $this->bot,
            'summary' => $this->summary,
            'patch_set' => $this->patchSet,
            'branch' => $this->branch,
            'open_delta' => $this->openDelta,
            'duration' => $this->duration,
            'first_review' => $this->firstReview,
        ];
    }

    public function dateTime(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->date, new \DateTimeZone('UTC'));
    }
}
