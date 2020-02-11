<?php

namespace AG\Tests\Events;

use AG\ElasticApmLaravel\Events\StopMeasuring;

class StopMeasuringTest extends \Codeception\Test\Unit
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
            'context' => 'extra-context'
        ]);

        $this->assertEquals([ 'context' => 'extra-context' ], $event->params);
    }
}
