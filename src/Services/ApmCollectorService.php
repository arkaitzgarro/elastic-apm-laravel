<?php

namespace AG\ElasticApmLaravel\Services;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Events\StartMeasuring;
use AG\ElasticApmLaravel\Events\StopMeasuring;
use PhilKra\Events\Transaction;
use Throwable;

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

    public function captureThrowable(Throwable $thrown, array $context = [], ?Transaction $parent = null)
    {
        if (false === config('elastic-apm-laravel.active')) {
            return;
        }

        app(Agent::class)->captureThrowable($thrown, $context, $parent);
    }
}
