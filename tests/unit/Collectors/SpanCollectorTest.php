<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use AG\ElasticApmLaravel\Collectors\SpanCollector;
use AG\ElasticApmLaravel\Events\StartMeasuring;
use AG\ElasticApmLaravel\Events\StopMeasuring;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as Config;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;

class SpanCollectorTest extends Unit
{
    /** @var Application */
    private $app;

    /** @var Dispatcher */
    private $dispatcher;

    /** @var CommandCollector */
    private $collector;

    protected function _before(): void
    {
        $this->app = app(Application::class);
        $this->dispatcher = app(Dispatcher::class);

        $this->collector = new SpanCollector(
            $this->app,
            new Config([]),
            new RequestStartTime(0.0)
        );
        $this->collector->useAgent(Mockery::mock(Agent::class));
    }

    protected function _after(): void
    {
        $this->dispatcher->forget(StartMeasuring::class);
        $this->dispatcher->forget(StopMeasuring::class);
    }

    public function testCollectorName(): void
    {
        self::assertEquals('span-collector', $this->collector->getName());
    }

    public function testRegisterListeners(): void
    {
        $this->dispatcher->dispatch(
            new StartMeasuring(
                'custom_span',
                'test_type',
                'test_action',
                'test_label'
            )
        );

        $this->dispatcher->dispatch(
            new StopMeasuring('custom_span')
        );

        self::assertCount(1, $this->collector->collect());

        $measure = $this->collector->collect()->get(0);
        self::assertEquals('test_label', $measure['label']);
        self::assertEquals('test_type', $measure['type']);
        self::assertEquals('test_action', $measure['action']);
        self::assertGreaterThan(0.0, $measure['start']);
        self::assertGreaterThan(0.0, $measure['duration']);
    }
}
