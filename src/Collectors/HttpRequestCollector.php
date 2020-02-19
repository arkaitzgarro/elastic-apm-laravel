<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Contracts\DataCollector;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;

/**
 * Collects info about the http request process.
 */
class HttpRequestCollector extends EventDataCollector implements DataCollector
{
    public function getName(): string
    {
        return 'request-collector';
    }

    public function registerEventListeners(): void
    {
        $this->app->booted(function () {
            $this->startMeasure('route_matching', 'laravel', 'request', 'Route matching');
        });

        // Time between route resolution and request handled
        $this->app->events->listen(RouteMatched::class, function () {
            $this->startMeasure('request_handled', 'laravel', 'request', $this->getController());
            $this->stopMeasure('route_matching');
        });

        $this->app->events->listen(RequestHandled::class, function () {
            // Some middlewares might return a response
            // before the RouteMatched has been dispatched
            if ($this->hasStartedMeasure('request_handled')) {
                $this->stopMeasure('request_handled');
            }
        });
    }

    protected function getController(): ?string
    {
        $router = $this->app['router'];

        $route = $router->current();
        $controller = $route ? $route->getActionName() : null;

        if ($controller instanceof \Closure) {
            $controller = 'anonymous function';
        } elseif (is_object($controller)) {
            $controller = 'instance of ' . get_class($controller);
        } elseif (is_array($controller) && 2 == count($controller)) {
            if (is_object($controller[0])) {
                $controller = get_class($controller[0]) . '->' . $controller[1];
            } else {
                $controller = $controller[0] . '::' . $controller[1];
            }
        } elseif (!is_string($controller)) {
            $controller = null;
        }

        return $controller;
    }
}
