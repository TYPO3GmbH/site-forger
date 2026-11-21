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
 * How Forge relates one issue to another, seen from the issue: the relation types of Redmine. Forge names each
 * relation from the side of its "issue_id". The other side has the reverse type, see reverse(). Duplicates are
 * kept apart, see ForgeIssueImporter::document().
 */
enum IssueRelationEnum: string
{
    case RELATES = 'relates';
    case BLOCKS = 'blocks';
    case BLOCKED_BY = 'blocked';
    case PRECEDES = 'precedes';
    case FOLLOWS = 'follows';
    case COPIED_TO = 'copied_to';
    case COPIED_FROM = 'copied_from';

    public function label(): string
    {
        return match ($this) {
            self::RELATES => 'Related to',
            self::BLOCKS => 'Blocks',
            self::BLOCKED_BY => 'Blocked by',
            self::PRECEDES => 'Precedes',
            self::FOLLOWS => 'Follows',
            self::COPIED_TO => 'Copied to',
            self::COPIED_FROM => 'Copied from',
        };
    }

    /**
     * The type of the relation seen from the other issue.
     */
    public function reverse(): self
    {
        return match ($this) {
            self::RELATES => self::RELATES,
            self::BLOCKS => self::BLOCKED_BY,
            self::BLOCKED_BY => self::BLOCKS,
            self::PRECEDES => self::FOLLOWS,
            self::FOLLOWS => self::PRECEDES,
            self::COPIED_TO => self::COPIED_FROM,
            self::COPIED_FROM => self::COPIED_TO,
        };
    }
}
