<?php
namespace AG\ElasticApmLaravel\Events;

use PhilKra\Events\Span as BaseSpan;

class Span extends BaseSpan
{
    protected $start_time;
    protected $duration;

    public function setStartTime(float $start_time): void
    {
        $this->start_time = $start_time;
    }

    public function getStartTime(): float
    {
        return $this->start_time;
    }

    public function setDuration(float $duration): void
    {
        $this->duration = $duration;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function jsonSerialize(): array
    {
         $json = parent::jsonSerialize();

         $json['span']['start'] = $this->getStartTime();
         $json['span']['duration'] = $this->getDuration();

         return $json;
    }
}
