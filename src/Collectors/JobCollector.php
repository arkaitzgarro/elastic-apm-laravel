<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\Interfaces\DataCollectorInterface;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Collects info about the http request process
 */
class JobCollector extends TimelineDataCollector implements DataCollectorInterface
{
    protected $app;
    protected $agent;

    public function __construct(Application $app, Agent $agent, float $request_start_time)
    {
        $this->app = $app;
        parent::__construct($request_start_time);

        $this->agent = $agent;
        $this->registerEventListeners();
    }

    public static function getName(): string
    {
        return 'job-collector';
    }

    protected function registerEventListeners(): void
    {
        $this->app->events->listen(JobProcessing::class, function () {
            $this->startMeasure('job_processing', 'job', 'processing', 'Job processing');
        });

        $this->app->events->listen(JobProcessed::class, function () {
            if ($this->hasStartedMeasure('job_processing')) {
                $this->stopMeasure('job_processing');
            }
        });
    }
}
