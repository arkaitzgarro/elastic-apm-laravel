<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\EventDataCollector;
use Codeception\Test\Unit;
use DMS\PHPUnitExtensions\ArraySubset\Assert;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;

class CustomCollector extends EventDataCollector
{
    public function getName(): string
    {
        return 'custom-collector';
    }

    public function registerEventListeners(): void
    {
        Log::info('registerEventListeners method has been called.');
    }
}

class CustomCollectorTest extends Unit
{
    private const SPAN_NAME = 'test-measure';

    /**
     * EventDataCollector instance.
     *
     * @var \AG\ElasticApmLaravel\Collectors\EventDataCollector
     */
    private $collector;

    protected function _before()
    {
        $appMock = Mockery::mock(Application::class);
        $agentMock = Mockery::mock(Agent::class);

        $agentMock->shouldReceive('getRequestStartTime')->andReturn(1000.0);
        Log::shouldReceive('info')
            ->once()
            ->with('registerEventListeners method has been called.');

        $this->collector = new CustomCollector($appMock, $agentMock);
    }

    public function testEmptyMeasures()
    {
        $this->assertEquals(0, $this->collector->collect()->count());
    }

    public function testCollectorName()
    {
        $this->assertEquals('custom-collector', $this->collector->getName());
    }

    public function testStartMeasure()
    {
        $this->collector->startMeasure(
            self::SPAN_NAME,
            'request',
            'GET',
            'GET /endpoint',
            1000.0
        );
        $event = $this->collector->collect()->first();

        $this->assertEquals(1, $this->collector->collect()->count());
        Assert::assertArraySubset([
            'label' => 'GET /endpoint',
            'start' => 0.0,
            'type' => 'request',
            'action' => 'GET',
            'context' => [],
        ], $event);
    }

    public function testStartMeasureDuplication()
    {
        Log::shouldReceive('warning')
            ->once()
            ->with("Did not start measure '" . self::SPAN_NAME . "' because it's already started.");

        $this->collector->startMeasure(self::SPAN_NAME);
        $this->collector->startMeasure(
            self::SPAN_NAME,
            'request',
            'GET',
            'GET /endpoint',
            1000.0
        );

        $this->assertEquals(1, $this->collector->collect()->count());
    }

    public function testHasStartedMeasure()
    {
        $this->collector->startMeasure(self::SPAN_NAME);
        $this->assertEquals(true, $this->collector->hasStartedMeasure(self::SPAN_NAME));
    }

    public function testNotStartedMeasure()
    {
        $this->assertEquals(false, $this->collector->hasStartedMeasure(self::SPAN_NAME));
    }

    public function testStopNonStartedMeasure()
    {
        Log::shouldReceive('warning')
            ->once()
            ->with("Did not stop measure '" . self::SPAN_NAME . "' because it hasn't been started.");

        $this->collector->stopMeasure(self::SPAN_NAME);

        $this->assertEquals(0, $this->collector->collect()->count());
    }
}
