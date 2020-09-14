<?php

namespace AG\ElasticApmLaravel;

use AG\ElasticApmLaravel\Middleware\RecordTransaction;
use AG\ElasticApmLaravel\Services\ApmCollectorService;
use AG\ElasticApmLaravel\Services\ApmConfigService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    private $config;
    private $source_config_path = __DIR__ . '/../config/elastic-apm-laravel.php';

    /**
     * Register the package Facade and APM agent.
     */
    public function register(): void
    {
        $this->mergeConfigFrom($this->source_config_path, 'elastic-apm-laravel');

        // Always available, even when inactive
        $this->registerConfigService();
        $this->registerFacades();

        if ($this->config->isAgentDisabled()) {
            return;
        }

        $this->registerAgent();
        $this->registerInitCollectors();
    }

    /**
     * Add the global transaction middleware
     * and default event collectors.
     */
    public function boot(): void
    {
        $this->publishConfig();

        if ($this->config->isAgentDisabled()) {
            return;
        }

        $this->registerMiddleware();
        $this->registerCollectors();
    }

    /**
     * Register Facades into the Service Container.
     */
    protected function registerFacades(): void
    {
        $this->app->bind('apm-collector', function ($app) {
            return $app->make(ApmCollectorService::class);
        });
    }

    /**
     * Register the Config Service into the Service Container.
     */
    protected function registerConfigService(): void
    {
        $this->config = $this->app->make(ApmConfigService::class);
        $this->app->instance(ApmConfigService::class, $this->config);
    }

    /**
     * Register the APM Agent into the Service Container.
     */
    protected function registerAgent(): void
    {
        $this->app->singleton(Agent::class, function () {
            $start_time = $this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true);

            return new Agent($this->config->getAgentConfig(), $start_time);
        });
    }

    /**
     * Add the middleware to the very top of the list,
     * aiming to have better time measurements.
     */
    protected function registerMiddleware(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->prependMiddleware(RecordTransaction::class);
    }

    /**
     * Register data collectors and start listening for events.
     */
    protected function registerCollectors(): void
    {
        $agent = $this->app->make(Agent::class);
        $agent->registerCollectors();
    }

    /**
     * Register data collectors that require starting earlier - before boot.
     */
    protected function registerInitCollectors(): void
    {
        $agent = $this->app->make(Agent::class);
        $agent->registerInitCollectors();
    }

    /**
     * Publish the config file.
     *
     * @param string $configPath
     */
    protected function publishConfig(): void
    {
        $this->publishes([$this->source_config_path => $this->getConfigPath()], 'config');
    }

    /**
     * Get the config path.
     */
    protected function getConfigPath(): string
    {
        return config_path('elastic-apm-laravel.php');
    }
}
