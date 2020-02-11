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
 * Collects info about the job process
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
            $transactionName = $this->getTransactionName($event);
            $this->addMetadata($transactionName, $event->job);
            $this->stopTransaction($transactionName);
        });
    }

    protected function startTransaction(string $transactionName): Transaction
    {
        return $this->agent->startTransaction(
            $transactionName,
            [],
            $this->request_start_time
        );
    }

    protected function addMetadata($transactionName, $job): void
    {
        $this->agent->getTransaction($transactionName)->setMeta([
            'type' => 'job'
        ]);
    }

    protected function stopTransaction($transactionName) : void
    {
        try {
            // Stop the transaction and measure the time
            $this->agent->stopTransaction($transactionName);
            $this->agent->sendTransaction($transactionName);
        } catch (Throwable $t) {
            Log::error($t->getMessage());
        }
    }

    protected function getTransactionName($event)
    {
        return Arr::get($event->job->payload(), 'displayName', 'Default');
    }
}
