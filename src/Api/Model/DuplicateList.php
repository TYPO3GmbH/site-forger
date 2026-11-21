<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use App\Duplicates\DuplicateGroups;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The open issues that may describe the same as an issue.')]
final readonly class DuplicateList
{
    /**
     * @param list<Duplicate> $items
     */
    public function __construct(
        #[OA\Property(description: 'The number of the issue asked for.', example: 105403)]
        public int $issue,
        #[OA\Property(
            description: 'The similarity in percent an issue the search found needs to be in the list.',
            example: DuplicateGroups::MIN_SIMILARITY,
        )]
        public int $minimumSimilarity,
        #[OA\Property(
            description: 'First the open issues Forge relates as duplicates, then the ones the search found, the most '
                . 'similar first.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: Duplicate::class)),
        )]
        public array $items,
    ) {
    }
}
