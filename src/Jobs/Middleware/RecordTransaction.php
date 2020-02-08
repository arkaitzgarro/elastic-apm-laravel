<?php

namespace AG\ElasticApmLaravel\Jobs\Middleware;

use AG\ElasticApmLaravel\Agent;
use Illuminate\Support\Facades\Log;
use PhilKra\Events\Transaction;
use Throwable;

class RecordTransaction
{
    /** @var Agent */
    private $agent;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    /**
     * Wrap the job processing in an APM transaction.
     *
     * @param  mixed  $job
     * @param  callable  $next
     * @return mixed
     */
    public function handle($job, $next)
    {
        if (config('elastic-apm-agent.active') === false) {
            return $next($job);
        }

        $transaction = $this->startTransaction($this->getTransactionName($job));

        $next($job);

        $this->addMetadata($transaction, $job);

        $this->stopTransaction($job);
    }

    public function addMetadata(Transaction $transaction, $job): void
    {
        $transaction->setMeta([
            'type' => 'job'
        ]);
    }

    /**
     * Start the transaction that will measure the job, application start up time, DB queries, etc
     */
    protected function startTransaction(string $transaction_name): Transaction
    {
        return $this->agent->startTransaction(
            $transaction_name,
            [],
            $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)
        );
    }

    protected function stopTransaction($job) : void
    {
        try {
            $transaction_name = $this->getTransactionName($job);

            // Stop the transaction and measure the time
            $this->agent->stopTransaction($transaction_name);
            $this->agent->sendTransaction($transaction_name);
        } catch (Throwable $t) {
            Log::error($t->getMessage());
        }
    }

    protected function getTransactionName($job)
    {
        return get_class($job);
    }
}
