<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Collectors\Interfaces\DataCollectorInterface;
use Illuminate\Foundation\Application;

/**
 * Collects info about the Laravel initialization.
 */
class FrameworkCollector extends TimelineDataCollector implements DataCollectorInterface
{
    protected $app;

    public function __construct(Application $app, float $request_start_time)
    {
        parent::__construct($request_start_time);

        $this->app = $app;
        $this->registerEventListeners();
    }

    public static function getName(): string
    {
        return 'framework-collector';
    }

    protected function registerEventListeners(): void
    {
        // Application and Laravel startup times
        // LARAVEL_START is defined at the entry point of the application
        // https://github.com/laravel/laravel/blob/master/public/index.php#L10
        $this->startMeasure('app_boot', 'app', 'boot', 'App boot', LARAVEL_START);

        $this->app->booting(function () {
            $this->startMeasure('laravel_boot', 'laravel', 'boot', 'Laravel boot');
            $this->stopMeasure('app_boot');
        });

        $this->app->booted(function () {
            if ($this->hasStartedMeasure('laravel_boot')) {
                $this->stopMeasure('laravel_boot');
            }
        });
    }
}
