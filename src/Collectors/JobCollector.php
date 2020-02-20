<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Contracts\DataCollector;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
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
            $this->stopTransaction($transaction_name);
            $this->setTransactionResult($transaction_name, 200);
        });

        $this->app->events->listen(JobFailed::class, function (JobFailed $event) {
            $transaction_name = $this->getTransactionName($event);
            $this->stopTransaction($transaction_name);
            $this->setTransactionResult($transaction_name, 500);
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
    protected function setTransactionResult(string $transaction_name, int $result): void
    {
        $this->agent->getTransaction($transaction_name)->setMeta([
            'result' => $result,
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
