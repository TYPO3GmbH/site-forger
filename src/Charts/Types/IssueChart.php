<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use App\Elastic\Indices;

abstract class IssueChart extends AbstractChart implements ChartInterface
{
    protected function getDocumentType(): string
    {
        return Indices::ISSUE;
    }
}
