<?php

namespace AG\ElasticApmLaravel;

use AG\ElasticApmLaravel\Contracts\VersionResolver;
use AG\ElasticApmLaravel\Middleware\RecordTransaction;
use AG\ElasticApmLaravel\Services\ApmCollectorService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Nipwaayoni\AgentBuilder;
use Nipwaayoni\Config;

class ServiceProvider extends BaseServiceProvider
{
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

        if ($this->isAgentDisabled()) {
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
     * Register the APM Agent into the Service Container.
     */
    protected function registerAgent(): void
    {
        $this->app->singleton(Agent::class, function () {
            $start_time = $this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true);

            /** @var AgentBuilder $builder */
            $builder = $this->app->make(AgentBuilder::class);

            $builder->withAgentClass(Agent::class);
            $builder->withConfig(new Config($this->getAgentConfig()));

            $builder->withEnvData(config('elastic-apm-laravel.env.env'));

            /** @var Agent $agent */
            $agent = $builder->build();

            $agent->setRequestStartTime($start_time);

            return $agent;
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
            || ('cli' === php_sapi_name() && false === config('elastic-apm-laravel.cli.active'));
    }
}
