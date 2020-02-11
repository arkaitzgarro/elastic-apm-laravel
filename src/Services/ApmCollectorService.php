<?php

namespace AG\ElasticApmLaravel\Services;

use AG\ElasticApmLaravel\Events\StartMeasuring;
use AG\ElasticApmLaravel\Events\StopMeasuring;

class ApmCollectorService
{
    public function startMeasure(
        string $name,
        string $type = 'request',
        ?string $action = null,
        ?string $label = null,
        ?float $start_time = null
    ) {
        event(new StartMeasuring($name, $type, $action, $label, $start_time));
    }

    public function stopMeasure(
        string $name,
        array $params = []
    ) {
        event(new StopMeasuring($name, $params));
    }
}
