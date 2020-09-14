<?php

namespace AG\ElasticApmLaravel\Jobs\Middleware;

use AG\ElasticApmLaravel\Facades\ApmCollector;
use AG\ElasticApmLaravel\Services\ApmConfigService;

class RecordTransaction
{
    private $config;

    public function __construct(ApmConfigService $config)
    {
        $this->config = $config;
    }

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
        if ($this->config->isAgentDisabled()) {
            return $next($job);
        }

        ApmCollector::startMeasure('job_processing', 'job', 'processing', get_class($job) . ' processing');

        $next($job);

        ApmCollector::stopMeasure('job_processing');
    }
}
