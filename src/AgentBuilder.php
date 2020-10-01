<?php

namespace AG\ElasticApmLaravel;

use AG\ElasticApmLaravel\Collectors\EventDataCollector;
use Illuminate\Support\Collection;
use Nipwaayoni\AgentBuilder as NipwaayoniAgentBuilder;
use Nipwaayoni\ApmAgent;
use Nipwaayoni\Config;
use Nipwaayoni\Contexts\ContextCollection;
use Nipwaayoni\Events\EventFactoryInterface;
use Nipwaayoni\Middleware\Connector;
use Nipwaayoni\Stores\TransactionsStore;

class AgentBuilder extends NipwaayoniAgentBuilder
{
    /** @var Collection */
    private $collectors;

    public function withEventCollectors(Collection $collectors): self
    {
        $this->collectors = $collectors;

        return $this;
    }

    protected function newAgent(
        Config $config,
        ContextCollection $shared_context,
        Connector $connector,
        EventFactoryInterface $event_factory,
        TransactionsStore $transactions_store): ApmAgent
    {
        if (null === $this->collectors) {
            $this->collectors = new Collection();
        }

        $agent = new Agent($config, $shared_context, $connector, $event_factory, $transactions_store, $this->startTime);

        $this->collectors->each(function (EventDataCollector $collector) use ($agent) {
            $agent->addCollector($collector);
        });

        return $agent;
    }
}
