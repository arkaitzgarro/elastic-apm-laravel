<?php

namespace AG\ElasticApmLaravel\Services;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Events\StartMeasuring;
use AG\ElasticApmLaravel\Events\StopMeasuring;
use Illuminate\Config\Repository as Config;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;

class ApmCollectorService
{
    /**
     * @var Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * @var Illuminate\Events\Dispatcher
     */
    protected $events;

    /**
     * @var bool
     */
    private $is_agent_disabled;

    public function __construct(Application $app, Dispatcher $events, Config $config)
    {
        $this->app = $app;
        $this->events = $events;

        $this->is_agent_disabled = false === $config->get('elastic-apm-laravel.active')
            || ('cli' === php_sapi_name() && false === $config->get('elastic-apm-laravel.cli.active'));
    }

    public function startMeasure(
        string $name,
        string $type = 'request',
        ?string $action = null,
        ?string $label = null,
        ?float $start_time = null
    ) {
        $this->events->dispatch(
            new StartMeasuring(
                $name,
                $type,
                $action,
                $label,
                $start_time
            )
        );
    }

    public function stopMeasure(
        string $name,
        array $params = []
    ) {
        $this->events->dispatch(
            new StopMeasuring(
                $name,
                $params
            )
        );
    }

    public function addCollector(string $collector_class): void
    {
        if ($this->is_agent_disabled) {
            return;
        }

        $this->app->make(Agent::class)->addCollector(
            $this->app->make($collector_class)
        );
    }
}
