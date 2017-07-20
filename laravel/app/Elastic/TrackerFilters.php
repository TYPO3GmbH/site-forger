<?php

namespace App\Elastic;

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
    public function isBug() : bool
    {
        return $this->bug;
    }

    /**
     * @param bool $bug
     */
    public function setBug($bug)
    {
        $this->bug = $bug;
    }

    /**
     * @return bool
     */
    public function isFeature() : bool
    {
        return $this->feature;
    }

    /**
     * @param bool $feature
     */
    public function setFeature($feature)
    {
        $this->feature = $feature;
    }

    /**
     * @return bool
     */
    public function isTask() : bool
    {
        return $this->task;
    }

    /**
     * @param bool $task
     */
    public function setTask($task)
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
    public function setStory($story)
    {
        $this->story = $story;
    }

    /**
     * @return bool
     */
    public function isEpic() : bool
    {
        return $this->epic;
    }

    /**
     * @param bool $epic
     */
    public function setEpic($epic)
    {
        $this->epic = $epic;
    }
}
