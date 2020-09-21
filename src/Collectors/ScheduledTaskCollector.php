<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Contracts\DataCollector;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Log;
use PhilKra\Events\Transaction;
use PhilKra\Exception\Transaction\UnknownTransactionException;
use Throwable;

/**
 * Collects info about scheduled tasks.
 */
class ScheduledTaskCollector extends EventDataCollector implements DataCollector
{
    public function getName(): string
    {
        return 'scheduled-task-collector';
    }

    public function registerEventListeners(): void
    {
        $this->app->events->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            $transaction_name = $this->getTransactionName($event);
            if (!$transaction_name) {
                return;
            }

            $transaction = $this->getTransaction($transaction_name);
            if ($transaction) {
                // Somehow, a transaction with the same name has already been created.
                // If so, ignore this job, otherwise the agent will throw an exception.
                return;
            }

            $this->startTransaction($transaction_name);
            $this->setTransactionType($transaction_name);
        });

        $this->app->events->listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) {
            $transaction_name = $this->getTransactionName($event);
            if ($transaction_name) {
                $transaction = $this->getTransaction($transaction_name);
                if ($transaction) {
                    $this->stopTransaction($transaction_name, 200);
                }
            }
        });

        $this->app->events->listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            $transaction_name = $this->getTransactionName($event);
            if ($transaction_name) {
                $transaction = $this->getTransaction($transaction_name);
                if ($transaction) {
                    $this->stopTransaction($transaction_name, 200);
                    $this->send($event);
                }
            }
        });
    }

    protected function getTransaction(string $transaction_name): ?Transaction
    {
        try {
            return $this->agent->getTransaction($transaction_name);
        } catch (UnknownTransactionException $e) {
            return null;
        }
    }

    protected function startTransaction(string $transaction_name): Transaction
    {
        $start_time = microtime(true);

        return $this->agent->startTransaction(
            $transaction_name,
            [],
            $start_time
        );
    }

    protected function setTransactionType(string $transaction_name): void
    {
        $this->agent->getTransaction($transaction_name)->setMeta([
            'type' => 'scheduled-task',
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

    protected function send($event): void
    {
        try {
            $this->agent->send();
        } catch (ClientException $exception) {
            Log::error($exception, ['api_response' => (string) $exception->getResponse()->getBody()]);
        } catch (Throwable $t) {
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
        $transaction_name = $event->task->command;

        return $this->shouldIgnoreTransaction($transaction_name) ? '' : $transaction_name;
    }

    protected function shouldIgnoreTransaction(string $transaction_name): bool
    {
        $pattern = $this->config->get('elastic-apm-laravel.transactions.ignorePatterns');

        return $pattern && preg_match($pattern, $transaction_name);
    }
}
