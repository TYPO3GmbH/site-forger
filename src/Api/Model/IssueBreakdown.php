<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * How the issues of a search spread, see ApiIssueQuery::breakdown().
 */
#[OA\Schema(description: 'How all issues of the search spread, not only those of the page. The most first.')]
final readonly class IssueBreakdown
{
    /**
     * @param list<Bucket> $status
     * @param list<Bucket> $tracker
     * @param list<Bucket> $category
     * @param list<Bucket> $createdYear
     * @param list<Bucket> $updatedYear
     */
    public function __construct(
        #[OA\Property(
            description: 'By status.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: Bucket::class)),
        )]
        public array $status,
        #[OA\Property(
            description: 'By tracker.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: Bucket::class)),
        )]
        public array $tracker,
        #[OA\Property(
            description: 'By category. Many issues have none.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: Bucket::class)),
        )]
        public array $category,
        #[OA\Property(
            description: 'By the year the issues were reported, the earliest first.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: Bucket::class)),
        )]
        public array $createdYear,
        #[OA\Property(
            description: 'By the year the issues changed last, the earliest first. It shows which "updatedBefore" '
                . 'leaves the old ones.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: Bucket::class)),
        )]
        public array $updatedYear,
    ) {
    }
}
