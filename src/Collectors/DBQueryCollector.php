<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Contracts\DataCollector;
use Exception;
use Illuminate\Database\Events\QueryExecuted;
use Jasny\DB\MySQL\QuerySplitter;

/**
 * Collects info about the database executed queries.
 */
class DBQueryCollector extends EventDataCollector implements DataCollector
{
    public function getName(): string
    {
        return 'query-collector';
    }

    public function registerEventListeners(): void
    {
        $this->app->events->listen(QueryExecuted::class, function (QueryExecuted $query) {
            $this->onQueryExecutedEvent($query);
        });
    }

    private function onQueryExecutedEvent(QueryExecuted $query): void
    {
        if ('auto' === $this->config->get('elastic-apm-laravel.spans.querylog.enabled')) {
            if ($query->time < $this->config->get('elastic-apm-laravel.spans.querylog.threshold')) {
                return;
            }
        }

        $start_time = microtime(true) - $this->start_time->microseconds() - $query->time / 1000;
        $end_time = $start_time + $query->time / 1000;

        $query = [
            'name' => $this->getQueryName($query->sql),
            'type' => 'db.mysql.query',
            'action' => 'query',
            'start' => $start_time,
            'end' => $end_time,
            'context' => [
                'db' => [
                    'statement' => (string) $query->sql,
                    'type' => 'sql',
                ],
            ],
        ];

        $this->addMeasure(
            $query['name'],
            $query['start'],
            $query['end'],
            $query['type'],
            $query['action'],
            $query['context']
        );
    }

    private function getQueryName(string $sql): string
    {
        $fallback = 'Eloquent Query';

        try {
            $query_type = QuerySplitter::getQueryType($sql);
            $tables = QuerySplitter::splitTables($sql);

            if (isset($query_type) && is_array($tables)) {
                // Query type and tables
                return $query_type . ' ' . join(', ', array_values($tables));
            }

            return $fallback;
        } catch (Exception $e) {
            return $fallback;
        }
    }
}
