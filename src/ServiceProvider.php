<?php
namespace AG\ElasticApmLaravel;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

use PhilKra\Helper\Timer;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Contracts\VersionResolver;
use AG\ElasticApmLaravel\Collectors\TimelineDataCollector;
use AG\ElasticApmLaravel\Collectors\DBQueryCollector;

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
         
        $this->listenForBooted();
        if (config('elastic-apm.spans.querylog.enabled') !== false) {
            $this->listenForQueries();
        }
    }

    /**
     * Register the APM Agent into the Service Container
     */
    protected function registerAgent(): void
    {
        $this->app->singleton(Agent::class, function () {
            $start_time = $this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true);
            $agent = new Agent($this->getAgentConfig(), $start_time);
            $agent->registerCollectors();

            return $agent;
        });
    }

    protected function listenForBooted(): void
    {
        $timeline_collector = $this->app->make(Agent::class)->getCollector(TimelineDataCollector::getName());
        $this->app->booted(function () use ($timeline_collector) {
            $start_time = $this->app['request']->server('REQUEST_TIME_FLOAT');
            if ($start_time && $start_time > 0) {
                $end_time = microtime(true) - $start_time;
                $timeline_collector->addMeasure('Laravel boot', 0, $end_time, 'laravel', 'boot');
            }
        });
    }

    protected function listenForQueries(): void
    {
        $query_collector = $this->app->make(Agent::class)->getCollector(DBQueryCollector::getName());
        $this->app->events->listen(QueryExecuted::class, function (QueryExecuted $query) use ($query_collector) {
            $query_collector->onQueryExecutedEvent($query);
        });
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
