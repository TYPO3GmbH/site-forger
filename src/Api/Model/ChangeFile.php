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

#[OA\Schema(description: 'A file the current patch set of a change touches.')]
final readonly class ChangeFile
{
    public const array STATUSES = ['added', 'modified', 'deleted', 'renamed', 'copied', 'rewritten'];

    public function __construct(
        #[OA\Property(
            description: 'The path in the repository.',
            example: 'typo3/sysext/backend/Classes/Preview/StandardContentPreviewRenderer.php',
        )]
        public string $path,
        #[OA\Property(
            description: 'What the patch set does to the file. Null, as the lines, for a change imported before '
                . 'Forger knew it.',
            enum: self::STATUSES,
            example: 'modified',
        )]
        public ?string $status,
        #[OA\Property(description: 'The lines it adds to the file.', example: 12)]
        public ?int $insertions,
        #[OA\Property(description: 'The lines it removes from the file.', example: 3)]
        public ?int $deletions,
        #[OA\Property(description: 'Whether the file is binary, without lines.', example: false)]
        public ?bool $binary,
        #[OA\Property(description: 'The path before, for a renamed or copied file.', example: null)]
        public ?string $oldPath,
    ) {
    }
}
