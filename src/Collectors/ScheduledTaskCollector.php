<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Contracts\DataCollector;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Nipwaayoni\Events\Transaction;

/**
 * Collects info about scheduled tasks.
 */
class ScheduledTaskCollector extends EventDataCollector implements DataCollector
{
    /**
     * When the task currently running started. Recorded for every task, including the
     * ones which are not being recorded as a transaction.
     *
     * @var float|null
     */
    private $task_started_at;

    public function getName(): string
    {
        return 'scheduled-task-collector';
    }

    public function registerEventListeners(): void
    {
        $this->app->events->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            $this->task_started_at = $this->event_clock->microtime();

            $transaction_name = $this->getTransactionName($event);
            if ($transaction_name) {
                $transaction = $this->getTransaction($transaction_name);
                if (!$transaction) {
                    $transaction = $this->startTransaction($transaction_name);
                    $this->addMetadata($transaction);
                }
            }
        });

        $this->app->events->listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) {
            $transaction_name = $this->getTransactionName($event);
            if ($transaction_name) {
                $transaction = $this->getTransaction($transaction_name);
                if ($transaction) {
                    $this->stopTransaction($transaction_name, $event->task->exitCode);
                    // A skipped task is a completed unit of work. Without sending, its
                    // spans stay pending and are collected again by the next task.
                    $this->send($event);

                    return;
                }
            }

            $this->agent->discardEvents($this->taskStartedAt());
        });

        $this->app->events->listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            $transaction_name = $this->getTransactionName($event);
            if ($transaction_name) {
                $transaction = $this->getTransaction($transaction_name);
                if ($transaction) {
                    $this->stopTransaction($transaction_name, $event->task->exitCode);
                    $this->send($event);

                    return;
                }
            }

            $this->agent->discardEvents($this->taskStartedAt());
        });
    }

    /**
     * Fall back to now, which discards nothing, when the task start was never seen.
     */
    private function taskStartedAt(): float
    {
        return $this->task_started_at ?? $this->event_clock->microtime();
    }

    protected function startTransaction(string $transaction_name): Transaction
    {
        return $this->agent->startTransaction(
            $transaction_name,
            [],
            $this->event_clock->microtime()
        );
    }

    protected function stopTransaction(string $transaction_name, ?int $result): void
    {
        // Stop the transaction and measure the time
        $this->agent->stopTransaction($transaction_name, ['result' => (int) $result]);
        $this->agent->collectEvents($transaction_name);
    }

    protected function send($event): void
    {
        try {
            $this->agent->send();
        } catch (ClientException $exception) {
            Log::error($exception, ['api_response' => (string) $exception->getResponse()->getBody()]);
        } catch (\Throwable $t) {
            Log::error($t->getMessage());
        }
    }

    /**
     * Return no name if we shouldn't record this transaction.
     *
     * @param ScheduledTaskStarting|ScheduledTaskSkipped|ScheduledTaskFinished $event
     */
    protected function getTransactionName($event): string
    {
        $transaction_name = $event->task instanceof CallbackEvent
            ? $event->task->getSummaryForDisplay()
            : $event->task->command;

        return $this->shouldIgnoreTransaction($transaction_name) ? '' : $transaction_name;
    }

    protected function addMetadata(Transaction $transaction): void
    {
        $transaction->setMeta([
            'type' => 'scheduled-task',
        ]);
        $transaction->setCustomContext([
            'ran_at' => Carbon::now()->toDateTimeString(),
            'memory' => [
                'peak' => round(memory_get_peak_usage(false) / 1024 / 1024, 2) . 'M',
                'peak_real' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'M',
            ],
        ]);
    }
}
