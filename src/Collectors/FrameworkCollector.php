<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Contracts\DataCollector;

/**
 * Collects info about the Laravel initialization.
 */
class FrameworkCollector extends EventDataCollector implements DataCollector
{
    public function getName(): string
    {
        return 'framework-collector';
    }

    public function registerEventListeners(): void
    {
        // Application and Laravel startup times
        // LARAVEL_START is defined at the entry point of the application
        // https://github.com/laravel/laravel/blob/507d499577e4f3edb51577e144b61e61de4fb57f/public/index.php#L6
        // But for serverless applications like Vapor or Octane,
        // the constant is not defined making the application fail.
        $start_time = defined('LARAVEL_START') ? constant('LARAVEL_START') : microtime(true);

        $this->startMeasure('app_boot', 'app', 'boot', 'App boot', $start_time);

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
