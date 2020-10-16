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
        define('LARAVEL_START', microtime(true));
        $this->app = app(Application::class);
        $this->collector = new FrameworkCollector(
            $this->app,
            new Repository([]),
            new RequestStartTime(microtime(true))
        );
    }

    public function testItCanRegisterBootingEvent(): void
    {
        $this->app->boot();

        self::assertCount(2, $this->collector->collect());
        self::assertFalse($this->collector->hasStartedMeasure('app_boot'));
        self::assertFalse($this->collector->hasStartedMeasure('laravel_boot'));
        // ...
    }
}
