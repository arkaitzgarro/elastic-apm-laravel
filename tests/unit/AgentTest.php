<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\EventCounter;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use AG\ElasticApmLaravel\EventClock;
use Codeception\Test\Unit;
use Illuminate\Config\Repository;
use Nipwaayoni\Config;
use Nipwaayoni\Contexts\ContextCollection;
use Nipwaayoni\Events\EventFactoryInterface;
use Nipwaayoni\Middleware\Connector;
use Nipwaayoni\Stores\TransactionsStore;

class AgentTest extends Unit
{
    /** @var Agent */
    private $agent;

    /** @var Config */
    private $config;
    /** @var ContextCollection */
    private $context;
    /** @var Mockery\Mock|Connector */
    private $connectorMock;
    /** @var Mockery\Mock|EventFactoryInterface */
    private $eventFactoryMock;
    /** @var TransactionsStore */
    private $transactionStore;
    /** @var Mockery\Mock|Repository */
    private $appConfigMock;
    /** @var RequestStartTime */
    private $requestStartTime;
    /** @var EventCounter */
    private $eventCounter;
    /** @var EventClock */
    private $eventClock;

    private $expectedCollectors = [];
    private $totalEvents = 0;

    protected function _before(): void
    {
        $this->config = new Config(['serviceName' => 'My Service']);
        $this->context = new ContextCollection([]);
        $this->connectorMock = Mockery::mock(Connector::class)->makePartial();
        $this->eventFactoryMock = Mockery::mock(EventFactoryInterface::class);
        $this->transactionStore = new TransactionsStore();
        $this->appConfigMock = Mockery::mock(Repository::class);

        $this->requestStartTime = new RequestStartTime(microtime(true));
        $this->eventCounter = new EventCounter();
        $this->eventClock = new EventClock();

        $this->agent = new Agent(
            $this->config,
            $this->context,
            $this->connectorMock,
            $this->eventFactoryMock,
            $this->transactionStore,
            $this->appConfigMock
        );
    }

    public function testAddsCollector(): void
    {
        /** @var Mockery\Mock|AG\ElasticApmLaravel\Contracts\DataCollector $collector */
        $collector = Mockery::mock(AG\ElasticApmLaravel\Collectors\SpanCollector::class)->makePartial();

        $this->agent->addCollector($collector);

        $this->assertSame($collector, $this->agent->getCollector('span-collector'));
    }

    public function testSetsSelfAsAgentWhenAddingCollector(): void
    {
        /** @var Mockery\Mock|AG\ElasticApmLaravel\Contracts\DataCollector $collector */
        $collector = Mockery::mock(AG\ElasticApmLaravel\Collectors\SpanCollector::class)->makePartial();

        $collector->shouldReceive('useAgent')->with($this->agent);

        $this->agent->addCollector($collector);
    }

    public function testAssertsDoesNotHaveCurrentTransaction(): void
    {
        $this->assertFalse($this->agent->hasCurrentTransaction());
    }

    public function testAssertsDoesHaveCurrentTransaction(): void
    {
        $this->agent->setCurrentTransaction(new \Nipwaayoni\Events\Transaction('test-transaction', []));

        $this->assertTrue($this->agent->hasCurrentTransaction());
    }

    public function testStartingTransactionSetsCurrentTransaction(): void
    {
        $this->eventFactoryMock->shouldReceive('newTransaction')
            ->andReturn(new \Nipwaayoni\Events\Transaction('test-transaction', []));

        $this->assertFalse($this->agent->hasCurrentTransaction());

        $this->agent->startTransaction('test-transaction');

        $this->assertTrue($this->agent->hasCurrentTransaction());
    }

    public function testReturnsCurrentTransaction(): void
    {
        $transaction = new \Nipwaayoni\Events\Transaction('test-transaction', []);

        $this->agent->setCurrentTransaction($transaction);

        $this->assertSame($transaction, $this->agent->currentTransaction());
    }

    public function testThrowsExceptionWhenNoCurrentTransaction(): void
    {
        $this->expectException(AG\ElasticApmLaravel\Exception\NoCurrentTransactionException::class);

        $this->agent->currentTransaction();
    }

    public function testClearsCurrentTransaction(): void
    {
        $this->agent->setCurrentTransaction(new \Nipwaayoni\Events\Transaction('test-transaction', []));

        $this->assertTrue($this->agent->hasCurrentTransaction());

        $this->agent->clearCurrentTransaction();

        $this->assertFalse($this->agent->hasCurrentTransaction());
    }

    public function testEventsAreAddedToConnectorWhenCollected(): void
    {
        $this->setupCollectors();

        $this->eventFactoryMock->shouldReceive('newTransaction')
            ->andReturn(new \Nipwaayoni\Events\Transaction('test-transaction', []));

        $spanMock = Mockery::mock(\Nipwaayoni\Events\Span::class);

        $this->eventFactoryMock->shouldReceive('newSpan')
            ->withArgs(function ($name) {
                $this->assertStringStartsWith('test-event', $name);

                return true;
            })
            ->andReturn($spanMock);

        $spanMock->shouldReceive(
            'setType',
            'setAction',
            'setCustomContext',
            'setStartOffset',
            'setDuration',
            'getEventType',
        );
        $spanMock->shouldReceive('isSampled')->passthru();

        // The `times()` constraint ensures we put the expected number of events
        $this->connectorMock->expects('putEvent')->times($this->totalEvents);

        $this->agent->startTransaction('test-transaction');
        $this->agent->collectEvents('test-transaction');
    }

    public function testStartNewTransactionSetsAsCurrent(): void
    {
        $transaction = new \Nipwaayoni\Events\Transaction('test-transaction', []);

        $this->eventFactoryMock->shouldReceive('newTransaction')
            ->andReturn($transaction);

        $this->agent->startTransaction('test-transaction');

        $this->assertSame($transaction, $this->agent->currentTransaction());
    }

    public function testCurrentTransactionIsClearedOnSend(): void
    {
        $this->connectorMock->shouldReceive('commit');

        $this->agent->setCurrentTransaction(new \Nipwaayoni\Events\Transaction('test-transaction', []));

        $this->assertTrue($this->agent->hasCurrentTransaction());

        $this->agent->send();

        $this->assertFalse($this->agent->hasCurrentTransaction());
    }

    public function testCollectorsAreResetOnSend(): void
    {
        $this->setupCollectors();

        $this->connectorMock->shouldReceive('commit');

        $this->agent->send();

        foreach (array_keys($this->expectedCollectors) as $type) {
            $this->asserttrue($this->expectedCollectors[$type]['object']->collect()->isEmpty());
        }
    }

    public function testPutsNewMetadataEventOnConnectorOnSend(): void
    {
        $this->connectorMock->shouldReceive('commit');
        $this->connectorMock->expects('putEvent')
            ->withArgs(function (Nipwaayoni\Events\EventBean $event) {
                $this->assertEquals('metadata', $event->getEventType());

                return true;
            });

        $this->agent->send();
    }

    private function setupCollectors(): void
    {
        $this->expectedCollectors = [
            'span' => [
                'class' => AG\ElasticApmLaravel\Collectors\SpanCollector::class,
                'eventCount' => 3,
                'object' => null,
            ],
            'db-query' => [
                'class' => AG\ElasticApmLaravel\Collectors\DBQueryCollector::class,
                'eventCount' => 5,
                'object' => null,
            ],
        ];

        $this->totalEvents = 0;

        $app = Mockery::mock(\Illuminate\Foundation\Application::class);
        // Expect the array accessor for `events` which returns an object with a listen() method
        $app->shouldReceive('offsetGet->listen');

        $config = Mockery::mock(Repository::class);

        // create collectors with events
        foreach (array_keys($this->expectedCollectors) as $type) {
            /** @var AG\ElasticApmLaravel\Collectors\EventDataCollector $collector */
            $collector = new $this->expectedCollectors[$type]['class']($app, $config, $this->requestStartTime, $this->eventCounter, $this->eventClock);

            for ($i = 0; $i < $this->expectedCollectors[$type]['eventCount']; ++$i) {
                $collector->addMeasure(uniqid('test-event'), 100, 200);
            }

            $this->agent->addCollector($collector);

            $this->expectedCollectors[$type]['object'] = $collector;

            $this->totalEvents += $this->expectedCollectors[$type]['eventCount'];
        }
    }
}
