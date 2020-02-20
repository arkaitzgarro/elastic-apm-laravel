<?php

use AG\ElasticApmLaravel\Events\StartMeasuring;
use Codeception\Test\Unit;

class StartMeasuringTest extends Unit
{
    public function testInstanceCreation()
    {
        $event = new StartMeasuring('event-name');

        $this->assertEquals('event-name', $event->name);
        $this->assertEquals('request', $event->type);
        $this->assertNull($event->action);
        $this->assertNull($event->label);
        $this->assertNull($event->start_time);
    }

    public function testExtraParameters()
    {
        $event = new StartMeasuring('event-name', 'query', 'select', 'DB Query', 1000.0);

        $this->assertEquals('query', $event->type);
        $this->assertEquals('select', $event->action);
        $this->assertEquals('DB Query', $event->label);
        $this->assertEquals(1000.0, $event->start_time);
    }
}
