<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

abstract class ReviewChart extends AbstractChart implements ChartInterface
{
    protected function getDocumentType(): string
    {
        return 'review';
    }

    /**
     * Changes in progress are left out: their owners do not ask for a review yet.
     */
    protected function scope(): array
    {
        return [['bool' => ['must_not' => [['term' => ['work_in_progress' => true]]]]]];
    }
}
