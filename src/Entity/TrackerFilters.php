<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

class TrackerFilters
{
    /**
     * Bug.
     *
     * @var bool
     */
    public $bug = false;

    /**
     * Feature.
     *
     * @var bool
     */
    public $feature = false;

    /**
     * Task.
     *
     * @var bool
     */
    public $task = false;

    /**
     * Story.
     *
     * @var bool
     */
    public $story = false;

    /**
     * Epic.
     *
     * @var bool
     */
    public $epic = false;

    public function __construct()
    {
        if (isset($_GET['trackerFilters']['epic'])) {
            $this->setEpic(true);
        }
        if (isset($_GET['trackerFilters']['story'])) {
            $this->setStory(true);
        }
        if (isset($_GET['trackerFilters']['bug'])) {
            $this->setBug(true);
        }
        if (isset($_GET['trackerFilters']['task'])) {
            $this->setTask(true);
        }
        if (isset($_GET['trackerFilters']['feature'])) {
            $this->setFeature(true);
        }
    }

    /**
     * @return bool
     */
    public function isBug(): bool
    {
        return $this->bug;
    }

    /**
     * @param bool $bug
     */
    public function setBug(bool $bug): void
    {
        $this->bug = $bug;
    }

    /**
     * @return bool
     */
    public function isFeature(): bool
    {
        return $this->feature;
    }

    /**
     * @param bool $feature
     */
    public function setFeature(bool $feature): void
    {
        $this->feature = $feature;
    }

    /**
     * @return bool
     */
    public function isTask(): bool
    {
        return $this->task;
    }

    /**
     * @param bool $task
     */
    public function setTask(bool $task): void
    {
        $this->task = $task;
    }

    /**
     * @return bool
     */
    public function isStory() : bool
    {
        return $this->story;
    }

    /**
     * @param bool $story
     */
    public function setStory(bool $story): void
    {
        $this->story = $story;
    }

    /**
     * @return bool
     */
    public function isEpic(): bool
    {
        return $this->epic;
    }

    /**
     * @param bool $epic
     */
    public function setEpic(bool $epic): void
    {
        $this->epic = $epic;
    }
}
