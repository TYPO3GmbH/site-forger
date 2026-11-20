<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report\Backport;

use App\Document\Change;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * A change merged into main and the versions it waits for, see Backports::rows().
 */
final readonly class BackportRow
{
    /**
     * How many versions have no backport yet.
     */
    public int $missing;

    /**
     * @param Change|null                              $change   null if Forger does not have the change in main
     * @param ReadableCollection<int, BackportVersion> $versions the newest first
     */
    public function __construct(
        public string $changeId,
        public int $number,
        public ?Change $change,
        public ReadableCollection $versions,
    ) {
        $missing = $versions->filter(static fn (BackportVersion $version): bool => $version->isMissing());
        $this->missing = $missing->count();
    }
}
