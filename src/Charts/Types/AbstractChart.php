<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use Elastica\Index;

abstract class AbstractChart
{
    /**
     * @var Index
     */
    protected $index;

    public function __construct(Index $index)
    {
        $this->index = $index;
    }

    public function convertDateToFormat(string $input, string $outputFormat = 'Y-m-d'): string
    {
        $dateTime = new \DateTimeImmutable($input);
        return $dateTime->format($outputFormat);
    }
}
