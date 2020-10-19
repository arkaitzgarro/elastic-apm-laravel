<?php

use AG\ElasticApmLaravel\Collectors\EventCounter;
use Codeception\Test\Unit;

class EventCounterTest extends Unit
{
    public function testUsesDefaultLimit(): void
    {
        $counter = new EventCounter();

        $this->assertEquals(1000, $counter->limit());
    }

    public function testUsesProvidedLimit(): void
    {
        $counter = new EventCounter(10);

        $this->assertEquals(10, $counter->limit());
    }

    public function testStartsWithZeroEvents(): void
    {
        $counter = new EventCounter();

        $this->assertEquals(0, $counter->count());
    }

    public function testIncrementsCount(): void
    {
        $counter = new EventCounter();

        $counter->increment();

        $this->assertEquals(1, $counter->count());
    }

    public function testIndicatesWhenLimitIsNotReached(): void
    {
        $counter = new EventCounter(2);

        $counter->increment();

        $this->assertFalse($counter->reachedLimit());
    }

    public function testIndicatesWhenLimitIsReached(): void
    {
        $counter = new EventCounter(2);

        $counter->increment();
        $counter->increment();

        $this->assertTrue($counter->reachedLimit());
    }

    public function testIndicatesWhenLimitIsExceeded(): void
    {
        $counter = new EventCounter(2);

        $counter->increment();
        $counter->increment();
        $counter->increment();

        $this->assertTrue($counter->reachedLimit());
    }

    public function testCanBeReset(): void
    {
        $counter = new EventCounter(2);

        $counter->increment();
        $counter->increment();
        $counter->reset();

        $this->assertEquals(0, $counter->count());
        $this->assertFalse($counter->reachedLimit());
    }
}
