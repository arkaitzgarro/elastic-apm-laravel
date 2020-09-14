<?php

namespace AG\ElasticApmLaravel\Services;

use AG\ElasticApmLaravel\Contracts\VersionResolver;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;

class ApmConfigService
{
    public function __construct(Application $app, ConfigRepository $configRepo)
    {
        $this->app = $app;
        $this->configRepo = $configRepo;
    }

    /**
     * Simple pass-through to the repository.
     */
    public function get($key, $default = null)
    {
        return $this->configRepo->get($key, $default);
    }

    public function isAgentDisabled(): bool
    {
        return false === $this->configRepo->get('elastic-apm-laravel.active')
            || ('cli' === php_sapi_name() && false === $this->configRepo->get('elastic-apm-laravel.cli.active'));
    }

    public function getAgentConfig(): array
    {
        return array_merge(
            [
                'framework' => 'Laravel',
                'frameworkVersion' => $this->app->version(),
            ],
            [
                'active' => $this->isAgentDisabled(),
                'httpClient' => $this->configRepo->get('elastic-apm-laravel.httpClient'),
            ],
            $this->getAppConfig(),
            $this->configRepo->get('elastic-apm-laravel.env'),
            $this->configRepo->get('elastic-apm-laravel.server')
        );
    }

    protected function getAppConfig(): array
    {
        $config = $this->configRepo->get('elastic-apm-laravel.app');
        if ($this->app->bound(VersionResolver::class)) {
            $config['appVersion'] = $this->app->make(VersionResolver::class)->getVersion();
        }

        return $config;
    }
}
