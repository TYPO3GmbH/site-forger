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
 * A file of a Forge issue, see ForgeIssueImporter::attachments().
 */
final readonly class IssueAttachment
{
    /**
     * @param string      $url       where Forge serves the file, without a login
     * @param string|null $createdOn as Forge sends it, like "2026-11-20T23:40:03Z"
     */
    public function __construct(
        public string $filename,
        public string $url,
        public int $size = 0,
        public string $contentType = '',
        public string $description = '',
        public ?Person $author = null,
        public ?string $createdOn = null,
    ) {
    }

    /**
     * @param mixed $attachments the field "attachments" of the issue index
     *
     * @return list<self>
     */
    public static function listFrom(mixed $attachments): array
    {
        $list = [];
        foreach ((array) $attachments as $file) {
            $filename = is_array($file) ? (string) ($file['filename'] ?? '') : '';
            $url = is_array($file) ? (string) ($file['content_url'] ?? '') : '';
            if ('' === $filename || '' === $url) {
                continue;
            }
            $list[] = new self(
                filename: $filename,
                url: $url,
                size: (int) ($file['filesize'] ?? 0),
                contentType: (string) ($file['content_type'] ?? ''),
                description: (string) ($file['description'] ?? ''),
                author: Person::fromIssue($file['author'] ?? null),
                createdOn: '' === (string) ($file['created_on'] ?? '') ? null : (string) $file['created_on'],
            );
        }

        return $list;
    }
}
