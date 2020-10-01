<?php

namespace AG\ElasticApmLaravel;

use AG\ElasticApmLaravel\Collectors\EventDataCollector;
use AG\ElasticApmLaravel\Contracts\DataCollector;
use AG\ElasticApmLaravel\Events\LazySpan;
use Illuminate\Support\Collection;
use Nipwaayoni\Agent as NipwaayoniAgent;
use Nipwaayoni\Config;
use Nipwaayoni\Contexts\ContextCollection;
use Nipwaayoni\Events\EventFactoryInterface;
use Nipwaayoni\Events\Metadata;
use Nipwaayoni\Middleware\Connector;
use Nipwaayoni\Stores\TransactionsStore;

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

    public function __construct(
        Config $config,
        ContextCollection $sharedContext,
        Connector $connector,
        EventFactoryInterface $eventFactory,
        TransactionsStore $transactionsStore
    ) {
        parent::__construct($config, $sharedContext, $connector, $eventFactory, $transactionsStore);

        $this->collectors = new Collection();
    }

    public function addCollector(DataCollector $collector): void
    {
        $collector->useAgent($this);

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

    public function send(): bool
    {
        $sent = parent::send();

        // Ensure collectors are reset after data is sent to APM
        $this->collectors->each(function (EventDataCollector $collector) {
            $collector->reset();
        });

        /*
         * Push new metadata onto the stack in preparation for the next send event. This
         * simulates the behavior when a new Agent is created and is needed for long running
         * worker processes. A future release of the Agent package should handle event
         * collection better and remove the need for this.
         */
        $this->putEvent(new Metadata([], $this->getConfig(), $this->agentMetadata()));

        return $sent;
    }
}
