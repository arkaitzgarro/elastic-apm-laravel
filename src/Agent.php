<?php

namespace AG\ElasticApmLaravel;

use AG\ElasticApmLaravel\Collectors\DBQueryCollector;
use AG\ElasticApmLaravel\Collectors\HttpRequestCollector;
use AG\ElasticApmLaravel\Collectors\Interfaces\DataCollectorInterface;
use AG\ElasticApmLaravel\Events\LazySpan;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use PhilKra\Agent as PhilKraAgent;

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
class Agent extends PhilKraAgent
{
    protected $collectors;
    protected $request_start_time;

    public function __construct(array $config, float $request_start_time)
    {
        parent::__construct($config);

        $this->request_start_time = $request_start_time;
        $this->collectors = new Collection();
    }

    public function registerCollectors(Application $app): void
    {
        if (false !== config('elastic-apm-laravel.spans.querylog.enabled')) {
            // DB Queries collector
            $this->collectors->put(
                DBQueryCollector::getName(),
                new DBQueryCollector($app, $this->request_start_time)
            );
        }

        // Http request collector
        $this->collectors->put(
            HttpRequestCollector::getName(),
            new HttpRequestCollector($app, $this->request_start_time)
        );
    }

    public function getCollector(string $name): DataCollectorInterface
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

    /**
     * Send Data to APM Service.
     */
    public function sendTransaction(string $transaction_name): bool
    {
        $this->collectEvents($transaction_name);

        return parent::send();
    }
}
