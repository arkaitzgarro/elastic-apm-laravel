<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Contracts\DataCollector;
use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Log;
use Nipwaayoni\Events\Transaction;
use Throwable;

/**
 * Collects info about ran commands.
 */
class CommandCollector extends EventDataCollector implements DataCollector
{
    public function getName(): string
    {
        return 'command-collector';
    }

    public function registerEventListeners(): void
    {
        $this->app->events->listen(CommandStarting::class, function (CommandStarting $event) {
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

            $transaction = $this->startTransaction($transaction_name);
            $this->addMetadata($transaction);
        });

        $this->app->events->listen(CommandFinished::class, function (CommandFinished $event) {
            $transaction_name = $this->getTransactionName($event);
            if ($transaction_name) {
                $transaction = $this->getTransaction($transaction_name);
                if ($transaction) {
                    $this->stopTransaction(
                        $transaction_name,
                        $event->exitCode
                    );
                    $this->send($event);
                }
            }
        });
    }

    protected function startTransaction(string $transaction_name): Transaction
    {
        return $this->agent->startTransaction(
            $transaction_name,
            [],
            $this->event_clock->microtime()
        );
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
     * @param CommandStarting|CommandFinished $event
     */
    protected function getTransactionName($event): string
    {
        $transaction_name = $event->command;

        if (null === $transaction_name) {
            return '';
        }

        return $this->shouldIgnoreTransaction($transaction_name) ? '' : $transaction_name;
    }

    protected function addMetadata(Transaction $transaction): void
    {
        $runner = null;
        if (extension_loaded('posix')) {
            $runner = posix_getpwuid(posix_geteuid())['name'];
        }
        $transaction->setUserContext([
            'username' => $runner,
        ]);
        $transaction->setMeta([
            'type' => 'command',
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
