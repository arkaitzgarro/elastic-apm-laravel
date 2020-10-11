<?php

use AG\ElasticApmLaravel\Collectors\EventCounter;
use AG\ElasticApmLaravel\Collectors\EventDataCollector;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use AG\ElasticApmLaravel\EventClock;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;

class EventDataCollectorTest extends Unit
{
    private const SPAN_NAME = 'test-measure';

    /** @var EventDataCollector */
    private $eventDataCollector;

    /** @var Application|\Mockery\Mock */
    private $appMock;
    /** @var Config|\Mockery\Mock */
    private $configMock;
    /** @var RequestStartTime|\Mockery\Mock */
    private $requestStartTimeMock;
    /** @var EventCounter */
    private $eventCounter;
    /** @var EventClock|\Mockery\Mock */
    private $eventClock;

    public static $registeredListeners = false;

    public function setUp(): void
    {
        $this->createEventCollector();

        parent::setUp();
    }

    public function testEmptyMeasures()
    {
        $this->assertEquals(0, $this->eventDataCollector->collect()->count());
    }

    public function testCollectorName()
    {
        $this->assertEquals('event-collector', $this->eventDataCollector->getName());
    }

    public function testRegistersListenersWhenCreated(): void
    {
        $this->assertTrue(self::$registeredListeners);
    }

    public function testStartMeasureAtCurrentTime()
    {
        // Expect the start time and end time
        $this->eventClock->shouldReceive('microtime')->andReturn(1500, 1900);

        $this->eventDataCollector->startMeasure(
            self::SPAN_NAME,
            'request',
            'GET',
            'GET /endpoint'
        );
        $event = $this->eventDataCollector->collect()->first();

        $this->assertEquals(1, $this->eventDataCollector->collect()->count());
        $this->assertSame([
            'label' => 'GET /endpoint',
            'start' => 500000.0,
            'duration' => 400000.0,
            'type' => 'request',
            'action' => 'GET',
            'context' => [],
        ], $event);
    }

    public function testStartMeasureWithFixedStartTime()
    {
        // Expect only the end time
        $this->eventClock->shouldReceive('microtime')->andReturn(1900);

        $this->eventDataCollector->startMeasure(
            self::SPAN_NAME,
            'request',
            'GET',
            'GET /endpoint',
            1500.0
        );
        $event = $this->eventDataCollector->collect()->first();

        $this->assertEquals(1, $this->eventDataCollector->collect()->count());
        $this->assertSame([
            'label' => 'GET /endpoint',
            'start' => 500000.0,
            'duration' => 400000.0,
            'type' => 'request',
            'action' => 'GET',
            'context' => [],
        ], $event);
    }

    public function testStartMeasureDuplication()
    {
        $this->eventClock->shouldReceive('microtime')->andReturn(1900);

        Log::shouldReceive('warning')
            ->once()
            ->with("Did not start measure '" . self::SPAN_NAME . "' because it's already started.");

        $this->eventDataCollector->startMeasure(self::SPAN_NAME);
        $this->eventDataCollector->startMeasure(
            self::SPAN_NAME,
            'request',
            'GET',
            'GET /endpoint',
            1000.0
        );

        $this->assertEquals(1, $this->eventDataCollector->collect()->count());
    }

    public function testHasStartedMeasure()
    {
        $this->eventClock->shouldReceive('microtime')->andReturn(1500);

        $this->eventDataCollector->startMeasure(self::SPAN_NAME);

        $this->assertTrue($this->eventDataCollector->hasStartedMeasure(self::SPAN_NAME));
    }

    public function testNotStartedMeasure()
    {
        $this->assertFalse($this->eventDataCollector->hasStartedMeasure(self::SPAN_NAME));
    }

    public function testStopNonStartedMeasure()
    {
        $this->eventClock->shouldReceive('microtime')->andReturn(1900);

        Log::shouldReceive('warning')
            ->once()
            ->with("Did not stop measure '" . self::SPAN_NAME . "' because it hasn't been started.");

        $this->eventDataCollector->stopMeasure(self::SPAN_NAME);

        $this->assertEquals(0, $this->eventDataCollector->collect()->count());
    }

    private function createEventCollector(): void
    {
        $this->appMock = Mockery::mock(Application::class);
        $this->configMock = Mockery::mock(Config::class);
        $this->requestStartTimeMock = Mockery::mock(RequestStartTime::class);
        $this->requestStartTimeMock->shouldReceive('microseconds')->andReturn(1000.0);

        $this->eventCounter = new EventCounter();

        $this->eventClock = Mockery::mock(EventClock::class);

        self::$registeredListeners = false;

        $this->eventDataCollector = new class($this->appMock, $this->configMock, $this->requestStartTimeMock, $this->eventCounter, $this->eventClock) extends EventDataCollector {
            public function getName(): string
            {
                return 'event-collector';
            }

            public function registerEventListeners(): void
            {
                EventDataCollectorTest::$registeredListeners = true;
            }
        };
    }
}
