<?php

namespace AG\ElasticApmLaravel;

use AG\ElasticApmLaravel\Collectors\DBQueryCollector;
use AG\ElasticApmLaravel\Collectors\FrameworkCollector;
use AG\ElasticApmLaravel\Collectors\HttpRequestCollector;
use AG\ElasticApmLaravel\Collectors\JobCollector;
use AG\ElasticApmLaravel\Collectors\SpanCollector;
use AG\ElasticApmLaravel\Contracts\DataCollector;
use AG\ElasticApmLaravel\Events\LazySpan;
use Illuminate\Support\Collection;
use Nipwaayoni\Agent as NipwaayoniAgent;

/**
 * The Elastic APM agent sends performance metrics and error logs to the APM Server.
 *
 * The agent records events, like HTTP requests and database queries.
 * The Agent automatically keeps track of queries to your data stores
 * to measure their duration and metadata (like the DB statement), as well as HTTP related information.
 *
 * These events, called Transactions and Spans, are sent to the APM Server.
 * The APM Server converts them to a format suitable for Elasticsearch,
 * and sends them to an Elasticsearch cluster. You can then use the APM app
 * in Kibana to gain insight into latency issues and error culprits within your application.
 */
class Agent extends NipwaayoniAgent
{
    protected $collectors;
    protected $request_start_time;

    /*
     * This method will be called by the parent's final constructor
     */
    protected function initialize(): void
    {
        $this->request_start_time = microtime(true);
        $this->collectors = new Collection();
    }

    public function registerInitCollectors(): void
    {
        // Laravel init collector
        if ('cli' !== php_sapi_name()) {
            // For cli executions, like queue workers, the application
            // only starts once. It doesn't really make sense to measure it.
            $this->addCollector(app(FrameworkCollector::class));
        }
    }

    public function registerCollectors(): void
    {
        if (false !== config('elastic-apm-laravel.spans.querylog.enabled')) {
            // DB Queries collector
            $this->addCollector(app(DBQueryCollector::class));
        }

        // Http request collector
        if ('cli' !== php_sapi_name()) {
            $this->addCollector(app(HttpRequestCollector::class));
        }

        // Job collector
        $this->addCollector(app(JobCollector::class));

        // Collector for manual measurements throughout the app
        $this->addCollector(app(SpanCollector::class));
    }

    public function addCollector(DataCollector $collector): void
    {
        $this->collectors->put(
            $collector->getName(),
            $collector
        );
    }

    public function getCollector(string $name): DataCollector
    {
        return $this->collectors->get($name);
    }

    public function collectEvents(string $transaction_name): void
    {
        $max_trace_items = config('elastic-apm-laravel.spans.maxTraceItems');

        $transaction = $this->getTransaction($transaction_name);
        $this->collectors->each(function ($collector) use ($transaction, $max_trace_items) {
            $collector->collect()->take($max_trace_items)->each(function ($measure) use ($transaction) {
                $event = new LazySpan($measure['label'], $transaction);
                $event->setType($measure['type']);
                $event->setAction($measure['action']);
                $event->setContext($measure['context']);
                $event->setStartTime($measure['start']);
                $event->setDuration($measure['duration']);

                $this->putEvent($event);
            });
        });
    }

    public function setRequestStartTime(float $startTime): void
    {
        $this->request_start_time = $startTime;
    }

    public function getRequestStartTime(): float
    {
        return $this->request_start_time;
    }
}
