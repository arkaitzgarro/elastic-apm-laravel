<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Contracts\DataCollector;
use AG\ElasticApmLaravel\EventClock;
use AG\ElasticApmLaravel\Helpers\StacktraceExtractor;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
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
    protected $event_clock;

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
        ?string $action = null,
        ?string $label = null,
        ?float $start_time = null,
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
            'started_at' => $start,
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

        // Use the private pushMeasure() method since using addMeasure would reject valid measures
        // created before the limit was reached
        $this->pushMeasure(
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
        ?array $context = [],
    ): void {
        if ($this->event_counter->reachedLimit()) {
            return;
        }

        $this->pushMeasure(
            $label,
            $start,
            $end,
            $type,
            $action,
            $context
        );

        $this->event_counter->increment();
    }

    // Only other exposed methods may push measures, this ensures the limit is respected
    private function pushMeasure(
        string $label,
        float $start,
        float $end,
        string $type = 'request',
        ?string $action = 'request',
        ?array $context = [],
    ): void {
        $this->measures->push([
            'label' => $label,
            'start' => $this->toMilliseconds($start),
            'duration' => $this->toMilliseconds($end - $start),
            'recorded_at' => $this->event_clock->microtime(),
            'type' => $type,
            'action' => $action,
            'context' => $context,
            'stacktrace' => StacktraceExtractor::getStacktrace($this->config),
        ]);
    }

    /**
     * Collecting consumes the measures. A measure belongs to exactly one transaction,
     * so leaving it pending would let the next transaction to collect claim it again.
     * This happens for a sync job, which stops its own transaction and collects, but
     * does not send because the request it runs inside has not finished yet.
     */
    public function collect(): Collection
    {
        $this->started_measures->keys()->each(function ($name) {
            $this->stopMeasure($name);
        });

        // recorded_at is internal bookkeeping used to attribute a measure to the unit
        // of work it belongs to, and is not part of the collected measure.
        $measures = $this->measures->map(function (array $measure) {
            unset($measure['recorded_at']);

            return $measure;
        });

        $this->measures = new Collection();

        return $measures;
    }

    /**
     * Drop the measures recorded since the given time, leaving anything recorded
     * before it untouched. Used when a unit of work is not being recorded as a
     * transaction, so that its measures are not attributed to a later one, while
     * an enclosing request or command keeps its own.
     */
    public function discardMeasuresRecordedSince(float $since): void
    {
        $this->measures = $this->measures
            ->reject(function (array $measure) use ($since) {
                return $measure['recorded_at'] >= $since;
            })
            ->values();

        $this->started_measures = $this->started_measures
            ->reject(function (array $measure) use ($since) {
                return $measure['started_at'] >= $since;
            });
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
