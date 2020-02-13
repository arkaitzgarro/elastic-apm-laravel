<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\Interfaces\DataCollectorInterface;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use PhilKra\Events\Transaction;
use Throwable;

/**
 * Collects info about the job process.
 */
class JobCollector extends TimelineDataCollector implements DataCollectorInterface
{
    protected $app;
    protected $agent;

    public function __construct(Application $app, Agent $agent, float $request_start_time)
    {
        parent::__construct($request_start_time);

        $this->app = $app;
        $this->agent = $agent;
        $this->registerEventListeners();
    }

    public static function getName(): string
    {
        return 'job-collector';
    }

    protected function registerEventListeners(): void
    {
        $this->app->events->listen(JobProcessing::class, function (JobProcessing $event) {
            $this->startTransaction($this->getTransactionName($event));
        });

        $this->app->events->listen(JobProcessed::class, function (JobProcessed $event) {
            $transaction_name = $this->getTransactionName($event);
            $this->addMetadata($transaction_name, $event->job);
            $this->stopTransaction($transaction_name);
        });
    }

    protected function startTransaction(string $transaction_name): Transaction
    {
        return $this->agent->startTransaction(
            $transaction_name,
            [],
            $this->request_start_time
        );
    }

    protected function addMetadata($transaction_name, $job): void
    {
        $this->agent->getTransaction($transaction_name)->setMeta([
            'type' => 'job',
        ]);
    }

    protected function stopTransaction($transaction_name): void
    {
        try {
            // Stop the transaction and measure the time
            $this->agent->stopTransaction($transaction_name);
            $this->agent->collectEvents($transaction_name);
        } catch (Throwable $t) {
            Log::error($t->getMessage());
        }
    }

    protected function getTransactionName($event)
    {
        return Arr::get($event->job->payload(), 'displayName', 'Default');
    }
}
