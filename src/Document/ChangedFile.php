<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

/**
 * A file the current patch set of a change touches, see GerritChangeImporter::fileDetails().
 *
 * Changes imported before version 4 of the review import have the path only, the other fields are null then.
 */
final readonly class ChangedFile
{
    /**
     * @param string|null $status added, modified, deleted, renamed, copied or rewritten
     */
    public function __construct(
        public string $path,
        public ?string $status = null,
        public ?int $insertions = null,
        public ?int $deletions = null,
        public ?bool $binary = null,
        public ?string $oldPath = null,
    ) {
    }

    /**
     * @param array<string, mixed> $file a file of "file_details"
     */
    public static function fromDocument(array $file): self
    {
        return new self(
            path: (string) ($file['path'] ?? ''),
            status: isset($file['status']) ? (string) $file['status'] : null,
            insertions: isset($file['insertions']) ? (int) $file['insertions'] : null,
            deletions: isset($file['deletions']) ? (int) $file['deletions'] : null,
            binary: isset($file['binary']) ? (bool) $file['binary'] : null,
            oldPath: isset($file['old_path']) ? (string) $file['old_path'] : null,
        );
    }
}
