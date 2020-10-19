<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\DBQueryCollector;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as Config;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;

class DBQueryCollectorTest extends Unit
{
    private const SQL = 'SELECT * FROM user WHERE id = ?';
    private const PROPRIETARY_SQL = 'NON_STANDARD * FROM user WHERE id = ?';

    /** @var Application */
    private $app;

    /** @var Dispatcher */
    private $dispatcher;

    /** @var DBQueryCollector */
    private $collector;

    /** @var Agent|LegacyMockInterface|MockInterface */
    private $agentMock;

    /** @var Config|LegacyMockInterface|MockInterface */
    private $configMock;

    /** @var Connection|LegacyMockInterface|MockInterface */
    private $connectionMock;

    protected function _before(): void
    {
        $this->app = app(Application::class);
        $this->dispatcher = app(Dispatcher::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->agentMock = Mockery::mock(Agent::class);
        $this->configMock = Mockery::mock(Config::class);
        $this->connectionMock = Mockery::mock(Connection::class);

        $this->connectionMock
            ->shouldReceive('getName');

        $this->collector = new DBQueryCollector(
            $this->app,
            $this->configMock,
            new RequestStartTime(0.0)
        );
        $this->collector->useAgent($this->agentMock);
    }

    protected function tearDown(): void
    {
        $this->dispatcher->forget(QueryExecuted::class);
    }

    public function testCollectorName(): void
    {
        self::assertEquals('query-collector', $this->collector->getName());
    }

    public function testQueryExecutedListener(): void
    {
        $this->configMock
            ->shouldReceive('get')
            ->with('elastic-apm-laravel.spans.querylog.enabled')
            ->andReturn(true);

        $this->dispatcher->dispatch(
            new QueryExecuted(
                self::SQL,
                [1],
                500,
                $this->connectionMock
            )
        );

        $measure = $this->collector->collect()->first();
        self::assertEquals('SELECT user', $measure['label']);
        self::assertGreaterThan(0.0, $measure['start']);
        self::assertEquals(500.0, $measure['duration']);
        self::assertEquals('db.mysql.query', $measure['type']);
        self::assertEquals('query', $measure['action']);
    }

    public function testDisabledFastQueryExecutedListener(): void
    {
        $this->configMock
            ->expects('get')
            ->with('elastic-apm-laravel.spans.querylog.enabled')
            ->andReturn('auto');

        $this->configMock
            ->expects('get')
            ->with('elastic-apm-laravel.spans.querylog.threshold')
            ->andReturn(1000.0);

        $this->dispatcher->dispatch(
            new QueryExecuted(
                self::SQL,
                [1],
                500,
                $this->connectionMock
            )
        );

        $measure = $this->collector->collect()->first();
        self::assertNull($measure);
    }

    public function testFallbackQueryName(): void
    {
        $this->configMock
            ->expects('get')
            ->with('elastic-apm-laravel.spans.querylog.enabled')
            ->andReturn(true);

        $this->dispatcher->dispatch(
            new QueryExecuted(
                self::PROPRIETARY_SQL,
                [1],
                500,
                $this->connectionMock
            )
        );

        $measure = $this->collector->collect()->first();
        self::assertEquals('Eloquent Query', $measure['label']);
        self::assertGreaterThan(0.0, $measure['start']);
        self::assertEquals(500.0, $measure['duration']);
        self::assertEquals('db.mysql.query', $measure['type']);
        self::assertEquals('query', $measure['action']);
    }
}
