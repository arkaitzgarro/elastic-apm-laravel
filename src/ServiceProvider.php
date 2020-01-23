<?php
namespace AG\ElasticApmLaravel;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Foundation\Http\Events\RequestHandled;
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
         
        $this->listenForEvents();
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

    protected function listenForEvents(): void
    {
        $start_time = $this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true);
        $timeline_collector = $this->app->make(Agent::class)->getCollector(TimelineDataCollector::getName());
        
        // Application and Laravel startup times
        $timeline_collector->startMeasure('app_boot', 'app', 'boot', 'App boot', $start_time);
        $this->app->booting(function () use ($timeline_collector) {
            $timeline_collector->stopMeasure('app_boot');
            $timeline_collector->startMeasure('laravel_boot', 'laravel', 'boot', 'Laravel boot');
        });
        $this->app->booted(function () use ($timeline_collector) {
            $timeline_collector->stopMeasure('laravel_boot');
        });

        // Time between route resolution and request handled
        $this->app->events->listen(RouteMatched::class, function () use ($timeline_collector) {
            $timeline_collector->startMeasure('request_handled', 'laravel', 'request', $this->getController());
        });
        $this->app->events->listen(RequestHandled::class, function () use ($timeline_collector) {
            if ($timeline_collector->hasStartedMeasure('request_handled')) {
                $timeline_collector->stopMeasure('request_handled');
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

    protected function getController()
    {
        $router = $this->app['router'];

        $route = $router->current();
        $controller = $route ? $route->getActionName() : null;

        if ($controller instanceof \Closure) {
            $controller = 'anonymous function';
        } elseif (is_object($controller)) {
            $controller = 'instance of ' . get_class($controller);
        } elseif (is_array($controller) && count($controller) == 2) {
            if (is_object($controller[0])) {
                $controller = get_class($controller[0]) . '->' . $controller[1];
            } else {
                $controller = $controller[0] . '::' . $controller[1];
            }
        } elseif (! is_string($controller)) {
            $controller = null;
        }

        return $controller;
    }
}
