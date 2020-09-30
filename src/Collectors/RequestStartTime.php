<?php

namespace AG\ElasticApmLaravel\Collectors;

class RequestStartTime
{
    /**
     * @var float
     */
    private $startTime;

    public function __construct(float $startTime)
    {
        $this->startTime = $startTime;
    }

    public function microseconds(): float
    {
        return $this->startTime;
    }
}
