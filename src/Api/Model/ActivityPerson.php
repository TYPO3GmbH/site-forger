<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A person of the activity: on Forge, on Gerrit, or both when Forger joined the accounts.')]
final readonly class ActivityPerson
{
    public function __construct(
        #[OA\Property(description: 'The name as Forge or Gerrit shows it.', example: 'Jane Doe')]
        public string $name,
        #[OA\Property(description: 'The number of the user on Forge, null when not known.', example: 36415)]
        public ?int $forgeId,
        #[OA\Property(description: 'The number of the account on Gerrit, null when not known.', example: 14410)]
        public ?int $gerritId,
        #[OA\Property(
            description: 'The key to filter the activity by the person, the parameter "person": "forge:<number>" '
                . 'or "gerrit:<number>".',
            example: 'forge:36415',
        )]
        public string $key,
        #[OA\Property(
            description: 'The address of the image of the person, 48 pixels wide: the avatar Forger shows, a '
                . 'placeholder without one.',
            example: 'https://www.gravatar.com/avatar/d41d8cd98f00b204e9800998ecf8427e?s=48&d=mp',
        )]
        public string $avatar,
        #[OA\Property(
            description: 'The username of the TYPO3 account, the same on Forge, Gerrit and my.typo3.org, where '
                . '"https://my.typo3.org/u/<username>" is the public profile. Null when not known.',
            example: 'jdoe',
        )]
        public ?string $username = null,
    ) {
    }
}
