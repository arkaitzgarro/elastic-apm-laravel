<?php

namespace AG\ElasticApmLaravel\Jobs\Middleware;

use AG\ElasticApmLaravel\Facades\ApmCollector;

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

        ApmCollector::startMeasure('job_processing', 'job', 'handle', get_class($job) . ' ::handle');

        $next($job);

        ApmCollector::stopMeasure('job_processing');
    }
}
