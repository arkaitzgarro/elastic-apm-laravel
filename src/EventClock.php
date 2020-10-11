<?php

namespace AG\ElasticApmLaravel;

class EventClock
{
    public function microtime(): float
    {
        return microtime(true);
    }
}
