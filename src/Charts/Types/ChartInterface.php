<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

interface ChartInterface
{
    /**
     * @return array<string, mixed> the data of a Chart.js chart
     */
    public function getData(): array;
}
