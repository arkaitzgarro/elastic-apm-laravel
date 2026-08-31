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
    private const EVENT_LIMIT = 2;

    /** @var EventDataCollector */
    private $eventDataCollector;

    /** @var Application|Mockery\Mock */
    private $appMock;
    /** @var Config|Mockery\Mock */
    private $configMock;
    /** @var RequestStartTime|Mockery\Mock */
    private $requestStartTimeMock;
    /** @var EventCounter */
    private $eventCounter;
    /** @var EventClock|Mockery\Mock */
    private $eventClock;

    public static $registeredListeners = false;

    public function _before(): void
    {
        $this->appMock = Mockery::mock(Application::class);
        $this->configMock = Mockery::mock(Config::class);
        $this->configMock->shouldReceive('get')
            ->with('elastic-apm-laravel.spans.renderSource', Mockery::any())
            ->andReturn(false);
        $this->requestStartTimeMock = Mockery::mock(RequestStartTime::class);
        $this->requestStartTimeMock->shouldReceive('microseconds')->andReturn(1000.0);
        $this->eventClock = Mockery::mock(EventClock::class);

        $this->eventCounter = new EventCounter(self::EVENT_LIMIT);

        $this->eventDataCollector = $this->createEventCollector();
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
        // Collecting consumes the measures, so gather them once
        $events = $this->eventDataCollector->collect();
        $event = $events->first();

        $this->assertEquals(1, $events->count());
        $this->assertSame([
            'label' => 'GET /endpoint',
            'start' => 500000.0,
            'duration' => 400000.0,
            'type' => 'request',
            'action' => 'GET',
            'context' => [],
            'stacktrace' => [],
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
        // Collecting consumes the measures, so gather them once
        $events = $this->eventDataCollector->collect();
        $event = $events->first();

        $this->assertEquals(1, $events->count());
        $this->assertSame([
            'label' => 'GET /endpoint',
            'start' => 500000.0,
            'duration' => 400000.0,
            'type' => 'request',
            'action' => 'GET',
            'context' => [],
            'stacktrace' => [],
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

    public function testStopsCollectingEventsWhenLimitIsReached(): void
    {
        $this->eventClock->shouldReceive('microtime')->andReturn(1500, 1550, 1600, 1650, 1700, 1750);

        $this->eventDataCollector->startMeasure(self::SPAN_NAME, 'request', 'GET', 'span 1');
        $this->eventDataCollector->stopMeasure(self::SPAN_NAME);
        $this->eventDataCollector->startMeasure(self::SPAN_NAME, 'request', 'GET', 'span 2');
        $this->eventDataCollector->stopMeasure(self::SPAN_NAME);
        $this->eventDataCollector->startMeasure(self::SPAN_NAME, 'request', 'GET', 'span 3');
        $this->eventDataCollector->stopMeasure(self::SPAN_NAME);

        $events = $this->eventDataCollector->collect();

        $this->assertCount(self::EVENT_LIMIT, $events);
    }

    public function testEventLimitAppliesAcrossCollectors(): void
    {
        $this->eventClock->shouldReceive('microtime')->andReturn(1500, 1550, 1600, 1650, 1700, 1750);
        $otherCollector = $this->createEventCollector();

        $this->eventDataCollector->startMeasure(self::SPAN_NAME, 'request', 'GET', 'span 1');
        $this->eventDataCollector->stopMeasure(self::SPAN_NAME);
        $otherCollector->startMeasure(self::SPAN_NAME, 'request', 'GET', 'span 2');
        $otherCollector->stopMeasure(self::SPAN_NAME);
        $otherCollector->startMeasure(self::SPAN_NAME, 'request', 'GET', 'span 3');
        $otherCollector->stopMeasure(self::SPAN_NAME);

        $events = $this->eventDataCollector->collect()->merge($otherCollector->collect());

        $this->assertCount(self::EVENT_LIMIT, $events);
    }

    public function testCollectsEventsByStartOrderWhenLimitIsReached(): void
    {
        $this->eventClock->shouldReceive('microtime')->andReturn(1500, 1600, 1700, 1550, 1650, 1750);

        // Event 3 should not be collected even though it is stopped first.
        $this->eventDataCollector->startMeasure(self::SPAN_NAME . ' 1', 'request', 'GET', 'span 1');
        $this->eventDataCollector->startMeasure(self::SPAN_NAME . ' 2', 'request', 'GET', 'span 2');
        $this->eventDataCollector->startMeasure(self::SPAN_NAME . ' 3', 'request', 'GET', 'span 3');
        $this->eventDataCollector->stopMeasure(self::SPAN_NAME . ' 3');
        $this->eventDataCollector->stopMeasure(self::SPAN_NAME . ' 2');
        $this->eventDataCollector->stopMeasure(self::SPAN_NAME . ' 1');

        $events = $this->eventDataCollector->collect();

        $this->assertCount(self::EVENT_LIMIT, $events);

        $events->each(function (array $eventData) {
            $this->assertNotEquals(self::SPAN_NAME . ' 3', $eventData['label']);
        });
    }

    public function testDirectAddingOfMeasuresRespectsLimit(): void
    {
        $this->eventClock->shouldReceive('microtime')->andReturn(1500);

        $this->eventDataCollector->addMeasure(uniqid('test-event'), 100, 200);
        $this->eventDataCollector->addMeasure(uniqid('test-event'), 100, 200);
        $this->eventDataCollector->addMeasure(uniqid('test-event'), 100, 200);

        $events = $this->eventDataCollector->collect();

        $this->assertCount(self::EVENT_LIMIT, $events);
    }

    public function testCollectingConsumesTheMeasures(): void
    {
        $this->eventClock->shouldReceive('microtime')->andReturn(1500);

        $this->eventDataCollector->addMeasure('only-measure', 100, 200);

        // A measure belongs to one transaction, so the next collect must not see it again
        $this->assertCount(1, $this->eventDataCollector->collect());
        $this->assertCount(0, $this->eventDataCollector->collect());
    }

    public function testDiscardsOnlyTheMeasuresRecordedSinceTheGivenTime(): void
    {
        $this->eventClock->shouldReceive('microtime')->andReturn(1000, 2000);

        $this->eventDataCollector->addMeasure('recorded-before', 100, 200);
        $this->eventDataCollector->addMeasure('recorded-after', 100, 200);

        $this->eventDataCollector->discardMeasuresRecordedSince(2000);

        $labels = $this->eventDataCollector->collect()->pluck('label')->all();

        $this->assertSame(['recorded-before'], $labels);
    }

    public function testDiscardsStartedMeasuresBegunSinceTheGivenTime(): void
    {
        $this->eventClock->shouldReceive('microtime')->andReturn(1000, 2000, 3000);

        $this->eventDataCollector->startMeasure('started-before', 'request', 'GET', 'before');
        $this->eventDataCollector->startMeasure('started-after', 'request', 'GET', 'after');

        $this->eventDataCollector->discardMeasuresRecordedSince(2000);

        $this->assertTrue($this->eventDataCollector->hasStartedMeasure('started-before'));
        $this->assertFalse($this->eventDataCollector->hasStartedMeasure('started-after'));
    }

    private function createEventCollector(): EventDataCollector
    {
        self::$registeredListeners = false;

        return new class($this->appMock, $this->configMock, $this->requestStartTimeMock, $this->eventCounter, $this->eventClock) extends EventDataCollector {
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
