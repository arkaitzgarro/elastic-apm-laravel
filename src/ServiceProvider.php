<?php
namespace AG\ElasticApmLaravel;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use PhilKra\Helper\Timer;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Contracts\VersionResolver;

class ServiceProvider extends BaseServiceProvider
{
    private $start_time;
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
        $this->startTransaction($this->registerAgent());
    }

    /**
     * Register the APM Agent into the Service Container
     */
    protected function registerAgent(): Agent
    {
        $agent = new Agent($this->getAgentConfig());
        $this->app->singleton(Agent::class, function () use ($agent) {
            return $agent;
        });

        return $agent;
    }

    /**
     * Start the transaction that will measure the request, application start up time,
     * DB queries, HTTP requests, etc
     */
    protected function startTransaction(Agent $agent): void
    {
        $transaction = $agent->startTransaction(
            $this->getTransactionName(),
            [],
            $_SERVER['REQUEST_TIME_FLOAT']
        );
        $boot_span = $agent->startSpan('Laravel boot', $transaction);
        $boot_span->setType('app');

        // Save the instance to stop the timer in the future
        $this->app->instance('boot_span', $boot_span);
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

    protected function getTransactionName(): string
    {
        return $_SERVER['REQUEST_METHOD'] . ' ' . $this->normalizeUri($_SERVER['REQUEST_URI']);
    }

    protected function normalizeUri(string $uri): string
    {
        // Fix leading /
        return '/' . trim($uri, '/');
    }
}
