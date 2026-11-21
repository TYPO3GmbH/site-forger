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
 * That DuplicateImport checked an issue for duplicates, and in which state of the issue.
 */
final readonly class DuplicateCheck
{
    public const string KIND = 'check';

    /**
     * @param string $issueUpdatedOn "updated_on" of the issue when it was checked
     */
    public function __construct(
        public int $issue,
        public string $issueUpdatedOn,
        public string $checkedOn,
    ) {
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromDocument(array $document): self
    {
        return new self(
            (int) (((array) ($document['issues'] ?? []))[0] ?? 0),
            (string) ($document['issue_updated_on'] ?? ''),
            (string) ($document['checked_on'] ?? ''),
        );
    }

    public function id(): string
    {
        return sprintf('%s-%d', self::KIND, $this->issue);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        return [
            'kind' => self::KIND,
            'issues' => [$this->issue],
            'issue_updated_on' => $this->issueUpdatedOn,
            'checked_on' => $this->checkedOn,
        ];
    }
}
