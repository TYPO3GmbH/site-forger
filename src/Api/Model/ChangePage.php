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

#[OA\Schema(description: 'One page of changes.')]
final readonly class ChangePage
{
    /**
     * @param list<ChangeSummary> $items
     */
    public function __construct(
        #[OA\Property(description: 'How many changes the search found.', example: 839)]
        public int $total,
        #[OA\Property(description: 'The position of the first item of the page.', example: 0)]
        public int $offset,
        #[OA\Property(description: 'The size of the page.', example: 25)]
        public int $limit,
        #[OA\Property(
            description: 'The changes of the page.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ChangeSummary::class)),
        )]
        public array $items,
    ) {
    }
}
