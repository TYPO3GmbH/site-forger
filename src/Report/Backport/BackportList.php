<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report\Backport;

use App\Repository\BackportBlacklistEntryRepository;
use App\Repository\ChangeRepository;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * The rows of the backport view: what app:backports found, without the changes excluded since.
 */
final readonly class BackportList
{
    public function __construct(
        private BackportFile $file,
        private BackportBlacklistEntryRepository $blacklist,
        private ChangeRepository $changes,
    ) {
    }

    /**
     * The rows, see Backports::rows(), null before app:backports wrote its file.
     *
     * @return ReadableCollection<int, BackportRow>|null
     */
    public function rows(): ?ReadableCollection
    {
        $backports = $this->file->read();
        if (null === $backports) {
            return null;
        }
        $backports = array_diff_key($backports, array_flip($this->blacklist->changeIds()));

        return Backports::rows($backports, $this->changes->byNumbers(Backports::mainNumbers($backports)));
    }
}
