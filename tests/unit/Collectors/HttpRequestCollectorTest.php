<?php

use AG\ElasticApmLaravel\Collectors\HttpRequestCollector;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as Config;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;

class HttpRequestCollectorTest extends Unit
{
    /** @var Application */
    private $app;

    /** @var Dispatcher */
    private $dispatcher;

    /** @var HttpRequestCollector */
    private $collector;

    /** @var Request */
    private $requestMock;

    /** @var Response */
    private $responseMock;

    /** @var Route */
    private $routeMock;

    public function _before(): void
    {
        $this->app = app(Application::class);
        $this->dispatcher = app(Dispatcher::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestMock = Mockery::mock(Request::class);
        $this->responseMock = Mockery::mock(Response::class);
        $this->routeMock = Mockery::mock(Route::class);

        $this->collector = new HttpRequestCollector(
            $this->app,
            new Config([]),
            new RequestStartTime(0.0)
        );
    }

    protected function tearDown(): void
    {
        $this->dispatcher->forget(RouteMatched::class);
        $this->dispatcher->forget(RequestHandled::class);
    }


    public function testCollectorName(): void
    {
        self::assertEquals('request-collector', $this->collector->getName());
    }

    public function testItCanRegisterRouteEvents(): void
    {
        $this->app->boot();

        $this->dispatcher->dispatch(
            new RouteMatched(
                $this->routeMock,
                $this->requestMock
            )
        );

        $this->dispatcher->dispatch(
            new RequestHandled(
                $this->requestMock,
                $this->responseMock
            )
        );

        self::assertCount(2, $this->collector->collect());

        $measure = $this->collector->collect()->get(0);
        self::assertEquals('Route matching', $measure['label']);
        self::assertEquals('laravel', $measure['type']);
        self::assertEquals('request', $measure['action']);
        self::assertGreaterThan(0.0, $measure['start']);
        self::assertGreaterThan(0.0, $measure['duration']);

        $measure = $this->collector->collect()->get(1);
        self::assertEquals('request_handled', $measure['label']);
        self::assertEquals('laravel', $measure['type']);
        self::assertEquals('request', $measure['action']);
        self::assertGreaterThan(0.0, $measure['start']);
        self::assertGreaterThan(0.0, $measure['duration']);
    }
}
