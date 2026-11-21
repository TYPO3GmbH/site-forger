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

#[OA\Schema(description: 'One page of issues.')]
final readonly class IssuePage
{
    /**
     * @param list<IssueSummary> $items
     */
    public function __construct(
        #[OA\Property(description: 'How many issues the search found.', example: 1547)]
        public int $total,
        #[OA\Property(description: 'The position of the first item of the page.', example: 0)]
        public int $offset,
        #[OA\Property(description: 'The size of the page.', example: 25)]
        public int $limit,
        #[OA\Property(
            description: 'The issues of the page.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: IssueSummary::class)),
        )]
        public array $items,
    ) {
    }
}
