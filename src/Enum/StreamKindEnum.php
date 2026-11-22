<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

/**
 * What happened in an event of the activity stream, on Forge or in Gerrit. Backed: the stream index stores it, and
 * the filter of the page and of the API takes it. See App\Stream\StreamEvents for how the notes of Forge and the
 * messages of Gerrit map to it.
 */
enum StreamKindEnum: string
{
    case CREATED = 'created';
    case COMMENTED = 'commented';
    case CHANGED = 'changed';
    case UPLOADED = 'uploaded';
    case VOTED = 'voted';
    case MERGED = 'merged';
    /**
     * An issue fixed: its status went to "Resolved". CLOSED is an issue closed or rejected without a fix, or a
     * change abandoned.
     */
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Created',
            self::COMMENTED => 'Commented',
            self::CHANGED => 'Changed',
            self::UPLOADED => 'New patch set',
            self::VOTED => 'Voted',
            self::MERGED => 'Merged',
            self::RESOLVED => 'Resolved',
            self::CLOSED => 'Closed',
        };
    }

    /**
     * What the person did, as the stream says it: "<person> <verb> <issue or change>".
     */
    public function verb(StreamSourceEnum $source): string
    {
        return match ($this) {
            self::CREATED => StreamSourceEnum::FORGE === $source ? 'reported' : 'pushed',
            self::COMMENTED => 'commented on',
            self::CHANGED => 'updated',
            self::UPLOADED => 'uploaded a patch set to',
            self::VOTED => 'voted on',
            self::MERGED => 'merged',
            self::RESOLVED => 'resolved',
            self::CLOSED => StreamSourceEnum::FORGE === $source ? 'closed' : 'abandoned',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CREATED => 'actions-plus-circle',
            self::COMMENTED => 'actions-comment',
            self::CHANGED => 'actions-document-edit',
            self::UPLOADED => 'actions-upload',
            self::VOTED => 'actions-check-circle',
            self::MERGED => 'actions-code-merge',
            self::RESOLVED => 'actions-check',
            self::CLOSED => 'actions-close',
        };
    }

    /**
     * The key of its color: the custom property "--stream-<key>" of assets/css/module/_stream.scss.
     */
    public function colorKey(): string
    {
        return 'kind-' . $this->value;
    }
}
