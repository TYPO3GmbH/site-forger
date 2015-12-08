<?php
namespace App\Elastic;

class TrackerFilters
{
    /**
     * Bug
     *
     * @var bool
     */
    public $bug = false;

    /**
     * Feature
     *
     * @var bool
     */
    public $feature = false;

    /**
     * Task
     *
     * @var bool
     */
    public $task = false;

    /**
     * Story
     *
     * @var bool
     */
    public $story = false;

    /**
     * Epic
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
     * @return boolean
     */
    public function isBug()
    {
        return $this->bug;
    }

    /**
     * @param boolean $bug
     */
    public function setBug($bug)
    {
        $this->bug = $bug;
    }

    /**
     * @return boolean
     */
    public function isFeature()
    {
        return $this->feature;
    }

    /**
     * @param boolean $feature
     */
    public function setFeature($feature)
    {
        $this->feature = $feature;
    }

    /**
     * @return boolean
     */
    public function isTask()
    {
        return $this->task;
    }

    /**
     * @param boolean $task
     */
    public function setTask($task)
    {
        $this->task = $task;
    }

    /**
     * @return boolean
     */
    public function isStory()
    {
        return $this->story;
    }

    /**
     * @param boolean $story
     */
    public function setStory($story)
    {
        $this->story = $story;
    }

    /**
     * @return boolean
     */
    public function isEpic()
    {
        return $this->epic;
    }

    /**
     * @param boolean $epic
     */
    public function setEpic($epic)
    {
        $this->epic = $epic;
    }
}
