<?php

namespace AG\ElasticApmLaravel;

use AG\ElasticApmLaravel\Collectors\DBQueryCollector;
use AG\ElasticApmLaravel\Collectors\FrameworkCollector;
use AG\ElasticApmLaravel\Collectors\HttpRequestCollector;
use AG\ElasticApmLaravel\Collectors\JobCollector;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use AG\ElasticApmLaravel\Collectors\SpanCollector;
use AG\ElasticApmLaravel\Contracts\VersionResolver;
use AG\ElasticApmLaravel\Middleware\RecordTransaction;
use AG\ElasticApmLaravel\Services\ApmCollectorService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Nipwaayoni\Config;

class ServiceProvider extends BaseServiceProvider
{
    public const COLLECTOR_TAG = 'event-collector';

    private $source_config_path = __DIR__ . '/../config/elastic-apm-laravel.php';

    /**
     * Register the package Facade and APM agent.
     */
    public function register(): void
    {
        $this->mergeConfigFrom($this->source_config_path, 'elastic-apm-laravel');

        // Always available, even when inactive
        $this->registerFacades();

        if ($this->isAgentDisabled()) {
            return;
        }

        // Create a single representation of the request start time which can be injected
        // to other classes.
        $this->app->singleton(RequestStartTime::class, function () {
            return new RequestStartTime($this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true));
        });

        $this->registerAgent();
        $this->registerCollectors();
    }

    /**
     * Add the global transaction middleware
     * and default event collectors.
     */
    public function boot(): void
    {
        $this->publishConfig();

        if ($this->isAgentDisabled()) {
            return;
        }

        $this->registerMiddleware();

        // If not collecting http events, the http middleware will not be executed and an
        // Agent will not exist prior to events occurring. Create one here to ensure the
        // collectors all register their listeners before any work is done. Unlike the
        // FrameWorkCollector, the JobCollector needs an Agent object so it cannot be
        // created independently and discovered by the ServiceProvider later.
        if (!$this->collectHttpEvents()) {
            $this->app->make(Agent::class);
        }
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
            /** @var AgentBuilder $builder */
            $builder = $this->app->make(AgentBuilder::class);

            return $builder
                ->withConfig(new Config($this->getAgentConfig()))
                ->withEnvData(config('elastic-apm-laravel.env.env'))
                ->withEventCollectors(collect($this->app->tagged(self::COLLECTOR_TAG)))
                ->build();
        });

        // Register a callback on terminating to send the events
        $this->app->terminating(function (Request $request, Response $response) {
            /** @var Agent $agent */
            $agent = $this->app->make(Agent::class);

            $agent->send();
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
     * Register data collectors and start listening for events. Most collectors are
     * registered by tagging the abstracts in the service container. The concreate
     * implementations are not created during registration.
     *
     * All collectors which must be created prior to the boot phase should ensure
     * they have no dependencies on other services which may not be registered yet.
     *
     * All tagged collectors will be gathered and given to the Agent when it is created.
     */
    protected function registerCollectors(): void
    {
        if ($this->collectFrameworkEvents()) {
            // Force the FrameworkCollector instance to be created and used. While this appears odd,
            // the collector instance registers itself to listen for booting events, so that instance
            // must be made available for collection later.
            $this->app->instance(FrameworkCollector::class, $this->app->make(FrameworkCollector::class));

            $this->app->tag(FrameworkCollector::class, self::COLLECTOR_TAG);
        }

        if (false !== config('elastic-apm-laravel.spans.querylog.enabled')) {
            // DB Queries collector
            $this->app->tag(DBQueryCollector::class, self::COLLECTOR_TAG);
        }

        // Http request collector
        if ($this->collectHttpEvents()) {
            $this->app->tag(HttpRequestCollector::class, self::COLLECTOR_TAG);
        }

        // Job collector
        $this->app->tag(JobCollector::class, self::COLLECTOR_TAG);

        // Collector for manual measurements throughout the app
        $this->app->tag(SpanCollector::class, self::COLLECTOR_TAG);
    }

    private function collectFrameworkEvents(): bool
    {
        // For cli executions, like queue workers, the application
        // only starts once. It doesn't really make sense to measure it.
        // Right now, the only condition that determines the inclusion
        // of framework events is being an http request. That may change
        // in the future, so we will use a specific method.
        return !$this->app->runningInConsole();
    }

    private function collectHttpEvents(): bool
    {
        return !$this->app->runningInConsole();
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
                'active' => config('elastic-apm-laravel.active'),
                'environment' => config('elastic-apm-laravel.env.environment'),
            ],
            $this->getAppConfig(),
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

    private function isAgentDisabled(): bool
    {
        return false === config('elastic-apm-laravel.active')
            || ($this->app->runningInConsole() && false === config('elastic-apm-laravel.cli.active'));
    }
}
