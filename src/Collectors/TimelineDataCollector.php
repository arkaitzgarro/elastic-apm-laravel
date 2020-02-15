<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Contracts\DataCollector;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Collects info about the request duration as well as providing
 * a way to log duration of any operations.
 */
abstract class TimelineDataCollector implements DataCollector
{
    /** @var Application */
    protected $app;

    /** @var Agent */
    protected $agent;

    /** @var Collection */
    protected $started_measures;

    /** @var Collection */
    protected $measures;

    /** @var float */
    protected $request_start_time;

    public function __construct(Application $app, Agent $agent)
    {
        $this->app = $app;
        $this->agent = $agent;

        $this->started_measures = new Collection();
        $this->measures = new Collection();

        $this->request_start_time = $this->agent->getRequestStartTime();

        $this->registerEventListeners();
    }

    public function getName(): string
    {
        return 'timeline';
    }

    /**
     * Starts a measure.
     */
    public function startMeasure(
        string $name,
        string $type = 'request',
        string $action = null,
        string $label = null,
        float $start_time = null
    ): void {
        $start = $start_time ?? microtime(true);
        if ($this->hasStartedMeasure($name)) {
            Log::warning("Did not start measure '{$name}' because it's already started.");

            return;
        }

        $this->started_measures->put($name, [
            'label' => $label ?: $name,
            'start' => $start - $this->request_start_time,
            'type' => $type,
            'action' => $action,
        ]);
    }

    /**
     * Check if a measure exists.
     */
    public function hasStartedMeasure(string $name): bool
    {
        return $this->started_measures->has($name);
    }

    /**
     * Stops a measure.
     */
    public function stopMeasure(string $name, array $params = []): void
    {
        $end = microtime(true);
        if (!$this->hasStartedMeasure($name)) {
            Log::warning("Did not stop measure '{$name}' because it hasn't been started.");

            return;
        }

        $measure = $this->started_measures->pull($name);
        $this->addMeasure(
            $measure['label'],
            $measure['start'],
            $end - $this->request_start_time,
            $measure['type'],
            $measure['action'],
            $params
        );
    }

    /**
     * Adds a measure.
     */
    public function addMeasure(
        string $label,
        float $start,
        float $end,
        string $type = 'request',
        ?string $action = 'request',
        ?array $context = []
    ): void {
        $this->measures->push([
            'label' => $label,
            'start' => $this->toMilliseconds($start),
            'duration' => $this->toMilliseconds($end - $start),
            'type' => $type,
            'action' => $action,
            'context' => $context,
        ]);
    }

    public function collect(): Collection
    {
        $this->started_measures->keys()->each(function ($name) {
            $this->stopMeasure($name);
        });

        return $this->measures;
    }

    protected function registerEventListeners(): void
    {
    }

    private function toMilliseconds(float $time): float
    {
        return round($time * 1000, 3);
    }
}
