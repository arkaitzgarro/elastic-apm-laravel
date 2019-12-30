<?php
namespace AG\ElasticApmLaravel;

use PhilKra\Agent as PhilKraAgent;
use PhilKra\Events\EventBean;
use PhilKra\Events\Span;

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
    public function startSpan(string $name, EventBean $parent): Span
    {
        // Create and Store a Span
        $span = $this->factory()->newSpan($name, $parent);
        $span->start();
        
        $this->putEvent($span);

        return $span;
    }
}
