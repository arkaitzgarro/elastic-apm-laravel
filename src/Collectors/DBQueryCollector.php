<?php
namespace AG\ElasticApmLaravel\Collectors;

use Exception;

use Illuminate\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;

use Jasny\DB\MySQL\QuerySplitter;

use AG\ElasticApmLaravel\Collectors\TimelineDataCollector;
use AG\ElasticApmLaravel\Contracts\DataCollector;

/**
 * Collects info about the database executed queries.
 */
class DBQueryCollector extends TimelineDataCollector implements DataCollector
{
    protected $app;

    public function __construct(Application $app, float $request_start_time)
    {
        parent::__construct($request_start_time);

        $this->app = $app;
        $this->registerEventListeners();
    }

    protected function registerEventListeners(): void
    {
        $this->app->events->listen(QueryExecuted::class, function (QueryExecuted $query) {
            $this->onQueryExecutedEvent($query);
        });
    }

    public function onQueryExecutedEvent(QueryExecuted $query): void
    {
        if (config('elastic-apm-laravel.spans.querylog.enabled') === 'auto') {
            if ($query->time < config('elastic-apm-laravel.spans.querylog.threshold')) {
                return;
            }
        }

        $start_time = microtime(true) - $this->request_start_time - $query->time / 1000;
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

    public static function getName(): string
    {
        return 'query-collector';
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
