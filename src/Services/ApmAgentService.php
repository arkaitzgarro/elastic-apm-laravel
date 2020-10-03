<?php

namespace AG\ElasticApmLaravel\Services;

use AG\ElasticApmLaravel\Agent;
use Illuminate\Foundation\Application;
use Nipwaayoni\Events\Transaction;
use Psr\Http\Message\RequestInterface;

class ApmAgentService
{
    /**
     * @var Application
     */
    protected $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function addTraceParentHeader(RequestInterface $request): RequestInterface
    {
        /** @var Agent $agent */
        $agent = $this->app->make(Agent::class);

        $transaction = $agent->currentTransaction();

        return $transaction->addTraceHeaderToRequest($request);
    }

    public function getCurrentTransaction(): Transaction
    {
        /** @var Agent $agent */
        $agent = $this->app->make(Agent::class);

        return $agent->currentTransaction();
    }
}
