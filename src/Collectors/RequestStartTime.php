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

    public function microseconds(): float
    {
        return $this->start_time;
    }
}
