<?php

namespace AG\ElasticApmLaravel;

use AG\ElasticApmLaravel\Contracts\VersionResolver;
use AG\ElasticApmLaravel\Middleware\RecordTransaction;
use AG\ElasticApmLaravel\Services\ApmCollectorService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    private $source_config_path = __DIR__ . '/../config/elastic-apm-laravel.php';

    /**
     * Bootstrap the application events.
     */
    public function boot(): void
    {
        $this->publishConfig();
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom($this->source_config_path, 'elastic-apm-laravel');

        // Always available, even when inactive
        $this->registerFacades();

        if (false === config('elastic-apm-laravel.active')) {
            return;
        }

        $this->registerAgent();
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
     * Register the APM Agent into the Service Container.
     */
    protected function registerAgent(): void
    {
        $this->app->singleton(Agent::class, function () {
            $start_time = $this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true);

            return new Agent($this->getAgentConfig(), $start_time);
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
        $agent->registerCollectors($this->app);
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

    protected function getAgentConfig(): array
    {
        return array_merge(
            [
                'framework' => 'Laravel',
                'frameworkVersion' => app()->version(),
            ],
            [
                'active' => config('elastic-apm-laravel.active'),
                'httpClient' => config('elastic-apm-laravel.httpClient'),
            ],
            $this->getAppConfig(),
            config('elastic-apm-laravel.env'),
            config('elastic-apm-laravel.server')
        );
    }

    protected function getAppConfig(): array
    {
        $config = config('elastic-apm-laravel.app');
        if ($this->app->bound(VersionResolver::class)) {
            $config['appVersion'] = $this->app->make(VersionResolver::class)->getVersion();
        }

        return $config;
    }
}
