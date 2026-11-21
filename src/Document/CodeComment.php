<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\People\Person;

/**
 * A comment on a change, see GerritChangeImporter::commentsOf(): on a line of a file, on a file, or on a patch set.
 * Gerrit publishes it with a message of the review log, the one of messageId.
 */
final readonly class CodeComment
{
    /**
     * @param string|null $file the path of the file, null for a comment on the patch set
     */
    public function __construct(
        public string $id,
        public ?string $messageId,
        public ?string $inReplyTo,
        public ?Person $author,
        public ?string $date,
        public ?int $patchSet,
        public ?string $file,
        public ?int $line,
        public bool $unresolved,
        public string $text,
    ) {
    }

    /**
     * @param array<string, mixed> $comment a comment of "comments"
     */
    public static function fromDocument(array $comment): self
    {
        return new self(
            id: (string) ($comment['id'] ?? ''),
            messageId: isset($comment['message_id']) ? (string) $comment['message_id'] : null,
            inReplyTo: isset($comment['in_reply_to']) ? (string) $comment['in_reply_to'] : null,
            author: Change::account($comment['author'] ?? null),
            date: isset($comment['date']) ? (string) $comment['date'] : null,
            patchSet: isset($comment['patch_set']) ? (int) $comment['patch_set'] : null,
            file: isset($comment['file']) ? (string) $comment['file'] : null,
            line: isset($comment['line']) ? (int) $comment['line'] : null,
            unresolved: true === ($comment['unresolved'] ?? false),
            text: (string) ($comment['message'] ?? ''),
        );
    }

    /**
     * The name of the file without its folder, null for a comment on the patch set.
     */
    public function fileName(): ?string
    {
        return null === $this->file ? null : basename($this->file);
    }
}
