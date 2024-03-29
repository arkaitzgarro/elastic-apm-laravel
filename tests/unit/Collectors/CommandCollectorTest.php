<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\CommandCollector;
use AG\ElasticApmLaravel\Collectors\EventCounter;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use AG\ElasticApmLaravel\EventClock;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as Config;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use Nipwaayoni\Events\Transaction;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CommandCollectorTest extends Unit
{
    private const COMMAND_NAME = 'work:do';

    // Use 4 backslashes to match a single backslash: https://stackoverflow.com/a/15369828
    private const COMMAND_IGNORE_PATTERN = '/(?:Application\\\\Commands\\\\DoWork|work:do)/';

    /** @var Application */
    private $app;

    /** @var Dispatcher */
    private $dispatcher;

    /** @var CommandCollector */
    private $collector;

    /** @var Agent|LegacyMockInterface|MockInterface */
    private $agentMock;

    /** @var Transaction|LegacyMockInterface|MockInterface */
    private $transactionMock;

    /** @var Config|LegacyMockInterface|MockInterface */
    private $configMock;

    /** @var EventClock|LegacyMockInterface|MockInterface */
    private $eventClockMock;

    /** @var LegacyMockInterface|MockInterface|InputInterface */
    private $commandInputMock;

    /** @var LegacyMockInterface|MockInterface|OutputInterface */
    private $commandOutputMock;

    protected function _before(): void
    {
        $this->app = app(Application::class);
        $this->dispatcher = app(Dispatcher::class);

        $this->commandInputMock = Mockery::mock(InputInterface::class);
        $this->commandOutputMock = Mockery::mock(OutputInterface::class);
        $this->transactionMock = Mockery::mock(Transaction::class);
        $this->agentMock = Mockery::mock(Agent::class);
        $requestStartTimeMock = Mockery::mock(RequestStartTime::class);
        $this->configMock = Mockery::mock(Config::class);
        $this->eventClockMock = Mockery::mock(EventClock::class);

        $eventCounter = new EventCounter();

        $this->collector = new CommandCollector(
            $this->app,
            $this->configMock,
            $requestStartTimeMock,
            $eventCounter,
            $this->eventClockMock
        );

        $this->collector->useAgent($this->agentMock);
    }

    protected function _after(): void
    {
        $this->dispatcher->forget(CommandStarting::class);
        $this->dispatcher->forget(CommandFinished::class);
    }

    protected function patternConfigReturn($configIgnore = null): void
    {
        $this->configMock->expects('get')
            ->with('elastic-apm-laravel.transactions.ignorePatterns')
            ->andReturn($configIgnore);
    }

    public function testCollectorName(): void
    {
        self::assertEquals('command-collector', $this->collector->getName());
    }

    public function testCommandStartingListenerIgnored(): void
    {
        $this->patternConfigReturn(self::COMMAND_IGNORE_PATTERN);
        $this->agentMock->shouldNotReceive('startTransaction', 'getTransaction');

        $this->dispatcher->dispatch(new CommandStarting(
            self::COMMAND_NAME,
            $this->commandInputMock,
            $this->commandOutputMock
        ));
    }

    public function testCommandFinishedListenerIgnored(): void
    {
        $this->patternConfigReturn(self::COMMAND_IGNORE_PATTERN);
        $this->agentMock->shouldNotReceive('getTransaction', 'captureThrowable', 'stopTransaction');

        $this->dispatcher->dispatch(new CommandFinished(
            self::COMMAND_NAME,
            $this->commandInputMock,
            $this->commandOutputMock,
            0
        ));
    }

    public function testDuplicatedTransactionForCommandStartingWillBeOmitted(): void
    {
        $this->patternConfigReturn();

        $this->agentMock->expects('getTransaction')
            ->with(self::COMMAND_NAME)
            ->andReturn($this->transactionMock);

        $this->dispatcher->dispatch(
            new CommandStarting(
                self::COMMAND_NAME,
                $this->commandInputMock,
                $this->commandOutputMock
            )
        );
    }

    public function testCommandStartingListener(): void
    {
        $this->patternConfigReturn();

        $this->eventClockMock->shouldReceive('microtime')->andReturn(1000);

        $this->agentMock->expects('getTransaction')
            ->with(self::COMMAND_NAME)
            ->andReturn(null);

        $this->agentMock->expects('startTransaction')
            ->with(self::COMMAND_NAME, [], 1000)
            ->andReturn($this->transactionMock);

        $this->dispatcher->dispatch(
            new CommandStarting(
                self::COMMAND_NAME,
                $this->commandInputMock,
                $this->commandOutputMock
            )
        );
    }

    public function testCommandFinishedListener(): void
    {
        $this->patternConfigReturn();

        $this->agentMock->expects('getTransaction')
            ->with(self::COMMAND_NAME)
            ->andReturn($this->transactionMock);
        $this->agentMock->expects('stopTransaction')->with(self::COMMAND_NAME, ['result' => 0]);
        $this->agentMock->expects('collectEvents')->with(self::COMMAND_NAME);
        $this->agentMock->expects('send');

        $this->dispatcher->dispatch(
            new CommandFinished(
                self::COMMAND_NAME,
                $this->commandInputMock,
                $this->commandOutputMock,
                0
            )
        );
    }

    public function testCommandFinishedButExceptionThrownOnSend(): void
    {
        $this->patternConfigReturn();

        $this->agentMock->expects('getTransaction')
            ->with(self::COMMAND_NAME)
            ->andReturn($this->transactionMock);
        $this->agentMock->expects('stopTransaction')->with(self::COMMAND_NAME, ['result' => 0]);
        $this->agentMock->expects('collectEvents')->with(self::COMMAND_NAME);
        $expectedLogMessage = 'snowball';
        $this->agentMock->expects('send')->andThrow(new Exception($expectedLogMessage));

        Log::shouldReceive('error')->once()->with($expectedLogMessage);

        $this->dispatcher->dispatch(
            new CommandFinished(
                self::COMMAND_NAME,
                $this->commandInputMock,
                $this->commandOutputMock,
                0
            )
        );
    }
}
