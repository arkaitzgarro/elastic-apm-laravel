<?php
namespace AG\ElasticApmLaravel;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use PhilKra\Helper\Timer;
use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Contracts\VersionResolver;
use AG\ElasticApmLaravel\Apm\SpanCollection;
use AG\ElasticApmLaravel\Apm\Transaction;

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
        if (config('elastic-apm.spans.querylog.enabled')) {
            $this->listenForQueries();
        }
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

        $this->startTime = $this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true);
        $timer = new Timer($this->startTime);
        $collection = new SpanCollection();
        $this->app->instance(Transaction::class, new Transaction($collection, $timer));
        $this->app->instance(Timer::class, $timer);
        $this->app->alias(Agent::class, 'elastic-apm');
        $this->app->instance('query-log', $collection);
    }

    /**
     * Register the APM Agent into the Service Container
     */
    protected function registerAgent(): void
    {
        $this->app->singleton(Agent::class, function () {
            return new Agent($this->getAgentConfig());
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

    protected function listenForQueries()
    {
        $this->app->events->listen(QueryExecuted::class, function (QueryExecuted $query) {
            if (config('elastic-apm.spans.querylog.enabled') === 'auto') {
                if ($query->time < config('elastic-apm.spans.querylog.threshold')) {
                    return;
                }
            }
            $stackTrace = $this->stripVendorTraces(
                collect(
                    debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, config('elastic-apm.spans.backtraceDepth', 50))
                )
            );
            $stackTrace = $stackTrace->map(function ($trace) {
                $sourceCode = $this->getSourceCode($trace);
                return [
                    'function' => Arr::get($trace, 'function') . Arr::get($trace, 'type') . Arr::get($trace,
                            'function'),
                    'abs_path' => Arr::get($trace, 'file'),
                    'filename' => basename(Arr::get($trace, 'file')),
                    'lineno' => Arr::get($trace, 'line', 0),
                    'library_frame' => false,
                    'vars' => $vars ?? null,
                    'pre_context' => optional($sourceCode->get('pre_context'))->toArray(),
                    'context_line' => optional($sourceCode->get('context_line'))->first(),
                    'post_context' => optional($sourceCode->get('post_context'))->toArray(),
                ];
            })->values();
            $query = [
                'name' => 'Eloquent Query',
                'type' => 'db.mysql.query',
                'start' => round((microtime(true) - $query->time / 1000 - $this->startTime) * 1000, 3),
                // calculate start time from duration
                'duration' => round($query->time, 3),
                'stacktrace' => $stackTrace,
                'context' => [
                    'db' => [
                        'instance' => $query->connection->getDatabaseName(),
                        'statement' => $query->sql,
                        'type' => 'sql',
                        'user' => $query->connection->getConfig('username'),
                    ],
                ],
            ];
            app('query-log')->push($query);
        });
    }

    /**
     * @param Collection $stackTrace
     * @return Collection
     */
    protected function stripVendorTraces(Collection $stackTrace): Collection
    {
        return collect($stackTrace)->filter(function ($trace) {
            return !Str::startsWith((Arr::get($trace, 'file')), [
                base_path() . '/vendor',
            ]);
        });
    }

    /**
     * @param array $stackTrace
     * @return Collection
     */
    protected function getSourceCode(array $stackTrace): Collection
    {
        if (config('elastic-apm.spans.renderSource', false) === false) {
            return collect([]);
        }
        if (empty(Arr::get($stackTrace, 'file'))) {
            return collect([]);
        }
        $fileLines = file(Arr::get($stackTrace, 'file'));
        return collect($fileLines)->filter(function ($code, $line) use ($stackTrace) {
            //file starts counting from 0, debug_stacktrace from 1
            $stackTraceLine = Arr::get($stackTrace, 'line') - 1;
            $lineStart = $stackTraceLine - 5;
            $lineStop = $stackTraceLine + 5;
            return $line >= $lineStart && $line <= $lineStop;
        })->groupBy(function ($code, $line) use ($stackTrace) {
            if ($line < Arr::get($stackTrace, 'line')) {
                return 'pre_context';
            }
            if ($line == Arr::get($stackTrace, 'line')) {
                return 'context_line';
            }
            if ($line > Arr::get($stackTrace, 'line')) {
                return 'post_context';
            }
            return 'trash';
        });
    }
}
