<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\DTO;

use Symfony\Component\HttpFoundation\Request;

readonly class TrackerFilters
{
    public bool $bug;
    public bool $epic;
    public bool $feature;
    public bool $story;
    public bool $task;

    public function __construct(Request $request)
    {
        $trackerFilters = $request->get('trackerFilters');

        $this->bug = isset($trackerFilters['bug']);
        $this->epic = isset($trackerFilters['epic']);
        $this->feature = isset($trackerFilters['feature']);
        $this->story = isset($trackerFilters['story']);
        $this->task = isset($trackerFilters['task']);
    }
}
