<?php
namespace AG\ElasticApmLaravel;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

use PhilKra\Helper\Timer;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Contracts\VersionResolver;

class ServiceProvider extends BaseServiceProvider
{
    private $source_config_path = __DIR__ . '/../config/elastic-apm-laravel.php';

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishConfig();
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom($this->source_config_path, 'elastic-apm-laravel');
        $this->registerAgent();
        $this->registerCollectors();
    }

    /**
     * Register the APM Agent into the Service Container
     */
    protected function registerAgent(): void
    {
        $this->app->singleton(Agent::class, function () {
            $start_time = $this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true);
            $agent = new Agent($this->getAgentConfig(), $start_time);

            return $agent;
        });
    }

    /**
     * Register data collectors and start listening for events
     */
    protected function registerCollectors(): void
    {
        if (config('elastic-apm-laravel.active') === false) {
            return;
        }

        $agent = $this->app->make(Agent::class);
        $agent->registerCollectors($this->app);
    }

    /**
     * Publish the config file
     *
     * @param  string $configPath
     */
    protected function publishConfig(): void
    {
        $this->publishes([$this->source_config_path => $this->getConfigPath()], 'config');
    }

    /**
     * Get the config path
     *
     * @return string
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
