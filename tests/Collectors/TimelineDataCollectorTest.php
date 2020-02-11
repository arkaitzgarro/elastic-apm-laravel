<?php

namespace AG\Tests\Collectors;

use AG\ElasticApmLaravel\Collectors\TimelineDataCollector;
use DMS\PHPUnitExtensions\ArraySubset\Assert;
use Illuminate\Support\Facades\Log;

class TimelineDataCollectorTest extends \Codeception\Test\Unit
{
    /**
     * TimelineDataCollector instance.
     *
     * @var \AG\ElasticApmLaravel\Collectors\TimelineDataCollector
     */
    private $collector;

    protected function _before()
    {
        $this->collector = new TimelineDataCollector(0);
    }

    public function testEmptyMeasures()
    {
        $this->assertEquals(0, $this->collector->collect()->count());
    }

    public function testCollectorName()
    {
        $this->assertEquals('timeline', TimelineDataCollector::getName());
    }

    public function testStartMeasure()
    {
        $this->collector->startMeasure(
            'test-measure',
            'request',
            'GET',
            'GET /endpoint',
            1000.0
        );
        $event = $this->collector->collect()->first();

        $this->assertEquals(1, $this->collector->collect()->count());
        Assert::assertArraySubset([
            'label' => 'GET /endpoint',
            'start' => 1000000.0,
            'type' => 'request',
            'action' => 'GET',
            'context' => [],
        ], $event);
    }

    public function testStartMeasureDuplication()
    {
        Log::shouldReceive('warning')
            ->once()
            ->with("Did not start measure 'test-measure' because it's already started.");

        $this->collector->startMeasure('test-measure');
        $this->collector->startMeasure(
            'test-measure',
            'request',
            'GET',
            'GET /endpoint',
            1000.0
        );

        $this->assertEquals(1, $this->collector->collect()->count());
    }

    public function testHasStartedMeasure()
    {
        $this->collector->startMeasure('test-measure');
        $this->assertEquals(true, $this->collector->hasStartedMeasure('test-measure'));
    }

    public function testNotStartedMeasure()
    {
        $this->assertEquals(false, $this->collector->hasStartedMeasure('test-measure'));
    }

    public function testStopNonStartedMeasure()
    {
        Log::shouldReceive('warning')
            ->once()
            ->with("Did not stop measure 'test-measure' because it hasn't been started.");

        $this->collector->stopMeasure('test-measure');

        $this->assertEquals(0, $this->collector->collect()->count());
    }
}
