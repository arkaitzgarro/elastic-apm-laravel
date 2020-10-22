<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\EventCounter;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use AG\ElasticApmLaravel\Collectors\ScheduledTaskCollector;
use AG\ElasticApmLaravel\EventClock;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as Config;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use Nipwaayoni\Events\Transaction;

class ScheduledTaskCollectorTest extends Unit
{
    private const COMMAND_NAME = 'command:name';

    // Use 4 backslashes to match a single backslash: https://stackoverflow.com/a/15369828
    private const TASK_IGNORE_PATTERN = '/(?:Application\\\\Commands\\\\DoWork|work:do)/';

    /** @var Application */
    private $app;

    /** @var Dispatcher */
    private $dispatcher;

    /** @var ScheduledTaskCollector */
    private $collector;

    /** @var Agent|LegacyMockInterface|MockInterface */
    private $agentMock;

    /** @var Transaction|LegacyMockInterface|MockInterface */
    private $transactionMock;

    /** @var Config|LegacyMockInterface|MockInterface */
    private $configMock;

    /** @var EventClock|LegacyMockInterface|MockInterface */
    private $eventClockMock;

    /** @var Event|LegacyMockInterface|MockInterface */
    private $eventMock;

    protected function _before(): void
    {
        $this->app = app(Application::class);
        $this->dispatcher = app(Dispatcher::class);

        $this->transactionMock = Mockery::mock(Transaction::class);
        $this->agentMock = Mockery::mock(Agent::class);
        $this->configMock = Mockery::mock(Config::class);
        $this->eventMock = Mockery::mock(Event::class);
        $this->eventClockMock = Mockery::mock(EventClock::class);

        $this->eventMock->command = self::COMMAND_NAME;
        $this->eventMock->exitCode = 0;

        $eventCounter = new EventCounter();

        $this->collector = new ScheduledTaskCollector(
            $this->app,
            $this->configMock,
            new RequestStartTime(0.0),
            $eventCounter,
            $this->eventClockMock
        );

        $this->collector->useAgent($this->agentMock);
    }

    protected function _after(): void
    {
        if (!class_exists('Illuminate\Console\Events\ScheduledTaskStarting')) {
            return;
        }

        $this->dispatcher->forget(\Illuminate\Console\Events\ScheduledTaskStarting::class);
        $this->dispatcher->forget(\Illuminate\Console\Events\ScheduledTaskSkipped::class);
        $this->dispatcher->forget(\Illuminate\Console\Events\ScheduledTaskFinished::class);
    }

    protected function patternConfigReturn($configIgnore = null): void
    {
        $this->configMock->expects('get')
            ->with('elastic-apm-laravel.transactions.ignorePatterns')
            ->andReturn($configIgnore);
    }

    public function testCollectorName(): void
    {
        self::assertEquals('scheduled-task-collector', $this->collector->getName());
    }

    public function testScheduledTaskStartingListenerIgnored(): void
    {
        if (!class_exists('Illuminate\Console\Events\ScheduledTaskStarting')) {
            return;
        }

        $this->eventMock->command = 'work:do';
        $this->patternConfigReturn(self::TASK_IGNORE_PATTERN);
        $this->agentMock->shouldNotReceive(['startTransaction', 'getTransaction']);

        $this->dispatcher->dispatch(new \Illuminate\Console\Events\ScheduledTaskStarting($this->eventMock));
    }

    public function testScheduledTaskSkippedListenerIgnored(): void
    {
        if (!class_exists('Illuminate\Console\Events\ScheduledTaskStarting')) {
            return;
        }

        $this->eventMock->command = 'work:do';
        $this->patternConfigReturn(self::TASK_IGNORE_PATTERN);
        $this->agentMock->shouldNotReceive(['startTransaction', 'getTransaction']);

        $this->dispatcher->dispatch(new \Illuminate\Console\Events\ScheduledTaskSkipped($this->eventMock));
    }

    public function testScheduledTaskFinishedListenerIgnored(): void
    {
        if (!class_exists('Illuminate\Console\Events\ScheduledTaskStarting')) {
            return;
        }

        $this->eventMock->command = 'work:do';
        $this->patternConfigReturn(self::TASK_IGNORE_PATTERN);
        $this->agentMock->shouldNotReceive(['startTransaction', 'getTransaction']);

        $this->dispatcher->dispatch(new \Illuminate\Console\Events\ScheduledTaskFinished($this->eventMock, 1000.0));
    }

    public function testScheduledTaskStartingListener(): void
    {
        if (!class_exists('Illuminate\Console\Events\ScheduledTaskStarting')) {
            return;
        }

        $this->patternConfigReturn();

        $this->eventClockMock->expects('microtime')->andReturn(1000);

        $this->agentMock->expects('getTransaction')
            ->with(self::COMMAND_NAME)
            ->andReturn(null);

        $this->agentMock->expects('startTransaction')
            ->with(self::COMMAND_NAME, [], 1000)
            ->andReturn($this->transactionMock);

        $this->dispatcher->dispatch(new \Illuminate\Console\Events\ScheduledTaskStarting($this->eventMock));
    }

    public function testScheduledTaskSkippedListener(): void
    {
        if (!class_exists('Illuminate\Console\Events\ScheduledTaskStarting')) {
            return;
        }

        $this->patternConfigReturn();

        $this->agentMock->expects('getTransaction')
            ->with(self::COMMAND_NAME)
            ->andReturn($this->transactionMock);

        $this->agentMock->expects('stopTransaction')
            ->with(self::COMMAND_NAME, ['result' => 0]);
        $this->agentMock->expects('collectEvents')
            ->with(self::COMMAND_NAME);

        $this->dispatcher->dispatch(new \Illuminate\Console\Events\ScheduledTaskSkipped($this->eventMock));
    }

    public function testScheduledTaskFinishedListener(): void
    {
        if (!class_exists('Illuminate\Console\Events\ScheduledTaskStarting')) {
            return;
        }

        $this->patternConfigReturn();

        $this->agentMock->expects('getTransaction')
            ->with(self::COMMAND_NAME)
            ->andReturn($this->transactionMock);

        $this->agentMock->expects('stopTransaction')
            ->with(self::COMMAND_NAME, ['result' => 0]);
        $this->agentMock->expects('collectEvents')
            ->with(self::COMMAND_NAME);
        $this->agentMock->expects('send');

        $this->dispatcher->dispatch(new \Illuminate\Console\Events\ScheduledTaskFinished($this->eventMock, 1000.0));
    }

    public function testCommandFinishedButExceptionThrownOnSend(): void
    {
        if (!class_exists('Illuminate\Console\Events\ScheduledTaskStarting')) {
            return;
        }

        $this->patternConfigReturn();

        $this->agentMock->expects('getTransaction')
            ->with(self::COMMAND_NAME)
            ->andReturn($this->transactionMock);
        $this->agentMock->expects('stopTransaction')
            ->with(self::COMMAND_NAME, ['result' => 0]);
        $this->agentMock->expects('collectEvents')
            ->with(self::COMMAND_NAME);

        $expectedLogMessage = 'snowball';
        $this->agentMock->expects('send')
            ->andThrow(new Exception($expectedLogMessage));

        Log::shouldReceive('error')->once()->with($expectedLogMessage);

        $this->dispatcher->dispatch(new \Illuminate\Console\Events\ScheduledTaskFinished($this->eventMock, 1000.0));
    }
}
