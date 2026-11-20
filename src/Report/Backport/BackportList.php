<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report\Backport;

use App\Elastic\Indices;
use App\Repository\BackportBlacklistEntryRepository;

/**
 * The rows of the backport view: what app:backports found, without the changes excluded since.
 */
final readonly class BackportList
{
    public function __construct(
        private BackportFile $file,
        private BackportBlacklistEntryRepository $blacklist,
        private Indices $indices,
    ) {
    }

    /**
     * The rows, see Backports::rows(), and whether app:backports ran yet.
     *
     * @return array{rows: list<array<string, mixed>>, fileExists: bool}
     */
    public function rows(): array
    {
        $backports = $this->file->read();
        if (null === $backports) {
            return ['rows' => [], 'fileExists' => false];
        }
        $backports = array_diff_key($backports, array_flip($this->blacklist->changeIds()));

        return ['rows' => Backports::rows($backports, $this->mainChanges($backports)), 'fileExists' => true];
    }

    /**
     * @param array<string, array<string, mixed>> $backports
     *
     * @return array<int, array<string, mixed>> the changes in main by their number
     */
    private function mainChanges(array $backports): array
    {
        $numbers = Backports::mainNumbers($backports);
        if ([] === $numbers) {
            return [];
        }
        $results = $this->indices->reviews()->createSearch([
            'query' => ['terms' => ['id' => $numbers]],
            'size' => count($numbers),
        ])->search()->getResults();
        $changes = [];
        foreach ($results as $result) {
            $changes[(int) $result->getData()['id']] = $result->getData();
        }

        return $changes;
    }
}
