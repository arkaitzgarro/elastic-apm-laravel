<?php
namespace AG\ElasticApmLaravel\Collectors;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Database\Events\QueryExecuted;
use AG\ElasticApmLaravel\Collectors\TimelineDataCollector;
use AG\ElasticApmLaravel\Collectors\Interfaces\DataCollectorInterface;

/**
 * Collects info about the database executed queries.
 */
class DBQueryCollector extends TimelineDataCollector implements DataCollectorInterface
{
    protected $request_start_time;

    public function __construct($request_start_time)
    {
        parent::__construct();

        $this->request_start_time = $request_start_time;
    }

    public function onQueryExecutedEvent(QueryExecuted $query): void
    {

        if (config('elastic-apm.spans.querylog.enabled') === 'auto') {
            if ($query->time < config('elastic-apm.spans.querylog.threshold')) {
                return;
            }
        }

        $start_time = microtime(true) - $this->request_start_time - $query->time / 1000;
        $end_time = $start_time + $query->time / 1000;

        $query = [
            'name' => 'Eloquent Query',
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
}
