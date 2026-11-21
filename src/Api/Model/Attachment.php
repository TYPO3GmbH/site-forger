<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use App\Api\ApiDate;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A file of an issue, as Forge lists it. Files added with a private comment are not in '
    . 'Forger.')]
final readonly class Attachment
{
    public function __construct(
        #[OA\Property(
            description: 'The name of the file. A comment shows an image by this name, like "!screenshot.png!".',
            example: 'screenshot.png',
        )]
        public string $filename,
        #[OA\Property(description: 'The media type, as Forge names it.', example: 'image/png')]
        public string $contentType,
        #[OA\Property(description: 'The size in bytes.', example: 48213)]
        public int $size,
        #[OA\Property(description: 'What the uploader wrote about the file. Mostly empty.', example: '')]
        public string $description,
        #[OA\Property(
            description: 'Who added it.',
            nullable: true,
            allOf: [new OA\Schema(ref: new Model(type: Person::class))],
        )]
        public ?Person $author,
        #[OA\Property(
            description: 'When it was added, which tells the comment it belongs to. ' . ApiDate::DESCRIPTION,
            format: 'date-time',
            example: ApiDate::EXAMPLE,
        )]
        public ?string $createdOn,
        #[OA\Property(
            description: 'Where Forge serves the file. It needs no login. Forger does not keep the file itself.',
            example: 'https://forge.typo3.org/attachments/download/38811/screenshot.png',
        )]
        public string $url,
    ) {
    }
}
