<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\Enum\IssueRelationEnum;

/**
 * A relation of Forge from an issue to another one, other than a duplicate, see ForgeIssueImporter::document().
 */
final readonly class IssueRelation
{
    public function __construct(
        public IssueRelationEnum $type,
        public int $issue,
    ) {
    }

    /**
     * @param mixed $relations the field "issue_relations" of the issue index
     *
     * @return list<self> without those of a type Forger does not know
     */
    public static function listFrom(mixed $relations): array
    {
        $list = [];
        foreach ((array) $relations as $relation) {
            $type = IssueRelationEnum::tryFrom((string) ($relation['type'] ?? ''));
            $issue = (int) ($relation['issue'] ?? 0);
            if (null !== $type && $issue > 0) {
                $list[] = new self($type, $issue);
            }
        }

        return $list;
    }
}
