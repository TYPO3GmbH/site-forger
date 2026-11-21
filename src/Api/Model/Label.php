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

#[OA\Schema(description: 'A label of a change people vote on, like "Code-Review".')]
final readonly class Label
{
    public const array VOTES = [-2, -1, 0, 1, 2];

    public const array STATES = ['OK', 'NEED', 'REJECT', 'IMPOSSIBLE', 'MAY'];

    /**
     * @param list<Vote> $votes
     */
    public function __construct(
        #[OA\Property(
            description: 'The vote of the label, with the negative votes of the CI but not its positive ones: a red '
                . 'build blocks the merge, a green one is no review. The lowest negative vote if there is '
                . 'one, else the highest. Only mergers vote +2 and -2.',
            enum: self::VOTES,
            example: 1,
        )]
        public int $vote,
        #[OA\Property(
            description: 'What the label lacks for a merge, as Gerrit sees it: "OK" has what it needs, "NEED" lacks a '
                . 'vote, "REJECT" has a veto. Null for a change imported before Forger knew it.',
            enum: self::STATES,
            example: 'NEED',
        )]
        public ?string $state,
        #[OA\Property(
            description: 'Each vote, the CI too. Empty for a change imported before Forger knew them.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: Vote::class)),
        )]
        public array $votes,
    ) {
    }
}
