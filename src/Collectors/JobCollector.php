<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Contracts\DataCollector;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use PhilKra\Events\Transaction;
use Throwable;

/**
 * Collects info about the job process.
 */
class JobCollector extends EventDataCollector implements DataCollector
{
    public function getName(): string
    {
        return 'job-collector';
    }

    public function registerEventListeners(): void
    {
        $this->app->events->listen(JobProcessing::class, function (JobProcessing $event) {
            $transaction_name = $this->getTransactionName($event);
            $this->startTransaction($transaction_name);
            $this->setTransactionType($transaction_name);
        });

        $this->app->events->listen(JobProcessed::class, function (JobProcessed $event) {
            $transaction_name = $this->getTransactionName($event);
            $this->stopTransaction($transaction_name, 200);
            $this->send($event->job);
        });

        $this->app->events->listen(JobFailed::class, function (JobFailed $event) {
            $transaction_name = $this->getTransactionName($event);
            $this->agent->captureThrowable($event->exception, [], $this->agent->getTransaction($transaction_name));
            $this->stopTransaction($transaction_name, 500);
            $this->send($event->job);
        });

        $this->app->events->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) {
            $transaction_name = $this->getTransactionName($event);
            $this->agent->captureThrowable($event->exception, [], $this->agent->getTransaction($transaction_name));
            $this->stopTransaction($transaction_name, 500);
            $this->send($event->job);
        });
    }

    protected function startTransaction(string $transaction_name): Transaction
    {
        $start_time = 'cli' === php_sapi_name() ? microtime(true) : $this->request_start_time;

        return $this->agent->startTransaction(
            $transaction_name,
            [],
            $start_time
        );
    }

    protected function setTransactionType(string $transaction_name): void
    {
        $this->agent->getTransaction($transaction_name)->setMeta([
            'type' => 'job',
        ]);
    }

    /**
     * Jobs don't have a response code like HTTP but we'll add the 200 success or 500 failure anyway
     * because it helps with filtering in Elastic.
     */
    protected function stopTransaction(string $transaction_name, int $result): void
    {
        // Stop the transaction and measure the time
        $this->agent->stopTransaction($transaction_name, ['result' => $result]);
        $this->agent->collectEvents($transaction_name);
    }

    protected function send(Job $job): void
    {
        try {
            if (!($job instanceof SyncJob)) {
                // When using a queued driver, send/flush transaction to make room for the next job in the queue
                // Otherwise just send when the agent destructs
                $this->agent->send();
            }
        } catch (Throwable $t) {
            Log::error($t->getMessage());
        }
    }

    protected function getTransactionName($event): string
    {
        return $event->job->resolveName();
    }
}
