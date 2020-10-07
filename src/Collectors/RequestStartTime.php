<?php

namespace AG\ElasticApmLaravel\Collectors;

class RequestStartTime
{
    /**
     * @var float
     */
    private $start_time;

    public function __construct(float $start_time)
    {
        $this->start_time = $start_time;
    }

    /**
     * Allow override the start time value for queued jobs,
     * where the application starts only once.
     */
    public function setStartTime(float $start_time): void
    {
        $this->start_time = $start_time;
    }

    public function microseconds(): float
    {
        return $this->start_time;
    }
}
