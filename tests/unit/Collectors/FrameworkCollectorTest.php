<?php

use AG\ElasticApmLaravel\Collectors\FrameworkCollector;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use Codeception\Test\Unit;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

class FrameworkCollectorTest extends Unit
{
    public $tester;

    /** @var Application */
    private $app;

    /** @var FrameworkCollector */
    private $collector;

    public function _before(): void
    {
        $this->app = app(Application::class);
        $this->collector = new FrameworkCollector(
            $this->app,
            new Repository([]),
            new RequestStartTime(0.0)
        );
    }

    public function testCollectorName(): void
    {
        self::assertEquals('framework-collector', $this->collector->getName());
    }

    public function testItCanRegisterBootingEvent(): void
    {
        $this->app->boot();

        self::assertCount(2, $this->collector->collect());

        $measure = $this->collector->collect()->get(0);
        self::assertEquals('App boot', $measure['label']);
        self::assertEquals('app', $measure['type']);
        self::assertEquals('boot', $measure['action']);
        self::assertEquals(0.0, $measure['start']);
        self::assertGreaterThan(0.0, $measure['duration']);

        $measure = $this->collector->collect()->get(1);
        self::assertEquals('Laravel boot', $measure['label']);
        self::assertEquals('laravel', $measure['type']);
        self::assertEquals('boot', $measure['action']);
        self::assertGreaterThan(0.0, $measure['start']);
        self::assertGreaterThan(0.0, $measure['duration']);
    }
}
