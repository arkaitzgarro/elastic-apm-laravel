<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Contracts\DataCollector;
use AG\ElasticApmLaravel\EventClock;
use Illuminate\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Nipwaayoni\Events\Transaction;
use Nipwaayoni\Exception\Transaction\UnknownTransactionException;

/**
 * Abstract class that provides base functionality to measure
 * events dispatched by the framework or your application code.
 */
abstract class EventDataCollector implements DataCollector
{
    /** @var Application */
    protected $app;

    /** @var Collection */
    protected $started_measures;

    /** @var Collection */
    protected $measures;

    /** @var Config */
    protected $config;

    /** @var RequestStartTime */
    protected $start_time;

    /** @var EventCounter */
    protected $event_counter;

    /** @var EventClock */
    private $event_clock;

    /** @var Agent */
    protected $agent;

    final public function __construct(Application $app, Config $config, RequestStartTime $start_time, EventCounter $event_counter, EventClock $event_clock)
    {
        $this->app = $app;
        $this->config = $config;
        $this->start_time = $start_time;
        $this->event_counter = $event_counter;
        $this->event_clock = $event_clock;

        $this->started_measures = new Collection();
        $this->measures = new Collection();

        $this->registerEventListeners();
    }

    public function useAgent(Agent $agent): void
    {
        $this->agent = $agent;
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
        $start = $start_time ?? $this->event_clock->microtime();
        if ($this->hasStartedMeasure($name)) {
            Log::warning("Did not start measure '{$name}' because it's already started.");

            return;
        }

        $transactionStart = $this->start_time->microseconds();
        $data = [
            'label' => $label ?: $name,
            'start' => $start - $transactionStart,
            'type' => $type,
            'action' => $action,
            'exceeds_limit' => $this->event_counter->reachedLimit(),
        ];

        $this->started_measures->put($name, $data);

        $this->event_counter->increment();
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
        $end = $this->event_clock->microtime();
        if (!$this->hasStartedMeasure($name)) {
            Log::warning("Did not stop measure '{$name}' because it hasn't been started.");

            return;
        }

        $measure = $this->started_measures->pull($name);

        if ($measure['exceeds_limit']) {
            return;
        }

        $this->addMeasure(
            $measure['label'],
            $measure['start'],
            $end - $this->start_time->microseconds(),
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
        // return if limit is exceeded
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

    private function toMilliseconds(float $time): float
    {
        return round($time * 1000, 3);
    }

    public function reset(): void
    {
        $this->started_measures = new Collection();
        $this->measures = new Collection();
    }

    protected function shouldIgnoreTransaction(string $transaction_name): bool
    {
        $pattern = $this->config->get('elastic-apm-laravel.transactions.ignorePatterns');

        return $pattern && preg_match($pattern, $transaction_name);
    }

    protected function getTransaction(string $transaction_name): ?Transaction
    {
        try {
            return $this->agent->getTransaction($transaction_name);
        } catch (UnknownTransactionException $e) {
            return null;
        }
    }
}
