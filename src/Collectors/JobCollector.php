<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Contracts\DataCollector;
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
class JobCollector extends TimelineDataCollector implements DataCollector
{
    public function getName(): string
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

    /**
     * $job is unused here but is included so that the extra info is available if someone extends this class
     * to add more detail in this method.
     */
    protected function addMetadata(string $transaction_name, $job): void
    {
        $this->agent->getTransaction($transaction_name)->setMeta([
            'type' => 'job',
        ]);
    }

    protected function stopTransaction(string $transaction_name): void
    {
        try {
            // Stop the transaction and measure the time
            $this->agent->stopTransaction($transaction_name);
            $this->agent->collectEvents($transaction_name);
        } catch (Throwable $t) {
            Log::error($t->getMessage());
        }
    }

    protected function getTransactionName($event): string
    {
        return $event->job->resolveName();
    }
}
