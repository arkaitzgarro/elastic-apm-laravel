<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\EventCounter;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use AG\ElasticApmLaravel\Collectors\ScheduledTaskCollector;
use AG\ElasticApmLaravel\EventClock;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as Config;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;

class ScheduledTaskCollectorTest extends Unit
{
    /** @var Application */
    private $app;

    /** @var Dispatcher */
    private $dispatcher;

    /** @var ScheduledTaskCollector */
    private $collector;

    protected function _before(): void
    {
        $this->app = app(Application::class);
        $this->dispatcher = app(Dispatcher::class);

        $eventCounter = new EventCounter();
        $eventClock = new EventClock();

        $this->collector = new ScheduledTaskCollector(
            $this->app,
            new Config([]),
            new RequestStartTime(0.0),
            $eventCounter,
            $eventClock
        );

        $this->collector->useAgent(Mockery::mock(Agent::class));
    }

    public function testCollectorName(): void
    {
        self::assertEquals('scheduled-task-collector', $this->collector->getName());
    }
}
