<?php

namespace AG\ElasticApmLaravel\Services;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Events\StartMeasuring;
use AG\ElasticApmLaravel\Events\StopMeasuring;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Nipwaayoni\Events\Transaction;

class ApmCollectorService
{
    /**
     * @var Application
     */
    protected $app;

    /**
     * @var Dispatcher
     */
    protected $events;

    /**
     * @var bool
     */
    private $is_agent_disabled;
    /**
     * @var Agent
     */
    private $agent;

    public function __construct(Application $app, Dispatcher $events, Config $config, Agent $agent)
    {
        $this->app = $app;
        $this->events = $events;
        $this->agent = $agent;

        $this->is_agent_disabled = false === $config->get('elastic-apm-laravel.active')
            || ($this->app->runningInConsole() && false === $config->get('elastic-apm-laravel.cli.active'));
    }

    public function startMeasure(
        string $name,
        string $type = 'request',
        ?string $action = null,
        ?string $label = null,
        ?float $start_time = null
    ): void {
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
    ): void {
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

        $this->agent->addCollector(
            $this->app->make($collector_class)
        );
    }

    public function captureThrowable(\Throwable $thrown, array $context = [], ?Transaction $parent = null): void
    {
        if ($this->is_agent_disabled) {
            return;
        }

        $this->agent->captureThrowable($thrown, $context, $parent);
    }
}
