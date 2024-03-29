<?php

namespace AG\ElasticApmLaravel\Jobs\Middleware;

use AG\ElasticApmLaravel\Facades\ApmCollector;

class RecordTransaction
{
    /**
     * Wrap the job processing in an APM transaction.
     */
    public function handle(mixed $job, callable $next)
    {
        if (false === config('elastic-apm-laravel.active') || false === config('elastic-apm-laravel.cli.active')) {
            return $next($job);
        }

        ApmCollector::startMeasure('job_processing', 'job', 'processing', get_class($job) . ' processing');

        $next($job);

        ApmCollector::stopMeasure('job_processing');
    }
}
