<?php

namespace AG\ElasticApmLaravel\Jobs\Middleware;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\JobCollector;

class RecordTransaction
{
    /**
     * Wrap the job processing in an APM transaction.
     *
     * @param mixed    $job
     * @param callable $next
     *
     * @return mixed
     */
    public function handle($job, $next)
    {
        if (false === config('elastic-apm-laravel.active') || false === config('elastic-apm-laravel.cli.active')) {
            return $next($job);
        }

        /** @var Agent */
        $agent = app(Agent::class);
        /** @var JobCollector */
        $collector = $agent->getCollector(JobCollector::getName());

        $collector->startMeasure('job_processing', 'job', 'processing', get_class($job) . ' processing');

        $next($job);

        $collector->stopMeasure('job_processing');
    }
}
