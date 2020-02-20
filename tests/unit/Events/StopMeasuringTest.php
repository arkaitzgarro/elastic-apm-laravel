<?php

use AG\ElasticApmLaravel\Events\StopMeasuring;
use Codeception\Test\Unit;

class StopMeasuringTest extends Unit
{
    public function testInstanceCreation()
    {
        $event = new StopMeasuring('event-name');

        $this->assertEquals('event-name', $event->name);
        $this->assertEquals([], $event->params);
    }

    public function testExtraParameters()
    {
        $event = new StopMeasuring('event-name', [
            'context' => 'extra-context',
        ]);

        $this->assertEquals(['context' => 'extra-context'], $event->params);
    }
}
