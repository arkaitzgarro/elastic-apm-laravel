<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\Interfaces\DataCollectorInterface;
use AG\ElasticApmLaravel\Events\StartMeasuring;
use AG\ElasticApmLaravel\Events\StopMeasuring;
use Illuminate\Foundation\Application;

/**
 * Generic collector for spans measured manually throughout the app.
 */
class SpanCollector extends TimelineDataCollector implements DataCollectorInterface
{
    public function __construct(Application $app, float $request_start_time)
    {
        parent::__construct($request_start_time);

        $this->app = $app;
        $this->registerEventListeners();
    }

    public static function getName(): string
    {
        return 'span-collector';
    }

    protected function registerEventListeners(): void
    {
        $this->app->events->listen(StartMeasuring::class, function (StartMeasuring $event) {
            // TODO: Throw exception or log warning if measure exists?
            $this->startMeasure(
                $event->name,
                $event->type,
                $event->action,
                $event->label,
                $event->startTime,
            );
        });

        $this->app->events->listen(StopMeasuring::class, function (StopMeasuring $event) {
            if ($this->hasStartedMeasure($event->name)) {
                $this->stopMeasure($event->name, $event->params);
            }
        });
    }
}
