<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use AG\ElasticApmLaravel\Middleware\RecordTransaction;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Nipwaayoni\Events\Transaction;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class RecordTransactionTest extends Unit
{
    /**
     * @var Agent|Mockery\MockInterface
     */
    private $agent;

    /**
     * @var RequestStartTime|Mockery\MockInterface
     */
    private $requestStartTimeMock;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var Response|Mockery\MockInterface
     */
    private $response;

    /**
     * @var RecordTransaction
     */
    private $middleware;

    /**
     * @var Transaction
     */
    private $transaction;

    protected function _before()
    {
        $this->agent = Mockery::mock(Agent::class);
        $this->requestStartTimeMock = Mockery::mock(RequestStartTime::class);
        $this->transaction = new Transaction('Test transaction', []);
        $this->request = Request::create('/ping', 'GET');
        $this->response = Mockery::mock(Response::class)->makePartial();
        $this->response->headers = new ResponseHeaderBag();
    }

    protected function createMiddlewareInstance(bool $use_route_uri, string $ignore_patterns = ''): void
    {
        $this->config = Mockery::mock(Config::class);
        $this->config->shouldReceive('get')
            ->with('elastic-apm-laravel.transactions.useRouteUri')
            ->andReturn($use_route_uri);
        $this->config->shouldReceive('get')
            ->with('elastic-apm-laravel.transactions.ignorePatterns')
            ->once()
            ->andReturn($ignore_patterns);
        $this->requestStartTimeMock->shouldReceive('microseconds')
            ->andReturn(1000.0);

        $this->middleware = new RecordTransaction(
            $this->agent,
            $this->config,
            $this->requestStartTimeMock,
        );
    }

    public function testStartTransaction()
    {
        $this->createMiddlewareInstance(false);

        $this->response->shouldReceive('getStatusCode')->andReturn(200);

        $this->agent->shouldReceive('startTransaction')
            ->once()
            ->withArgs(function ($transaction_name, $context, $request_time) {
                $this->assertEquals('GET /ping', $transaction_name);
                $this->assertEquals([], $context);
                $this->assertNotNull($request_time);

                return true;
            })
            ->andReturn($this->transaction);

        $this->middleware->handle($this->request, function () {
            return $this->response;
        });
    }

    public function testTransactionMetadata()
    {
        $this->createMiddlewareInstance(false);

        $this->agent->shouldReceive('startTransaction')
            ->once()
            ->andReturn($this->transaction);

        $this->response->shouldReceive('getStatusCode')
            ->times(2)
            ->andReturn(200);

        $this->middleware->handle($this->request, function () {
            return $this->response;
        });

        $data = $this->getTransactionData();

        $this->assertEquals(200, $data['result']);
        $this->assertEquals('HTTP', $data['type']);
    }

    public function testTransactionContext()
    {
        $this->createMiddlewareInstance(false);

        $this->agent->shouldReceive('startTransaction')
            ->once()
            ->andReturn($this->transaction);

        $this->response->shouldReceive('getStatusCode')
            ->times(2)
            ->andReturn(200);

        $this->middleware->handle($this->request, function () {
            return $this->response;
        });

        $data = $this->getTransactionData();

        $context = $data['context'];

        $this->assertTrue($context['response']['finished']);
        $this->assertTrue($context['response']['headers_sent']);
        $this->assertEquals(200, $context['response']['status_code']);

        $this->assertEquals([
            'id' => null,
            'ip' => '127.0.0.1',
            'user-agent' => 'Symfony',
        ], $context['user']);
    }

    public function testUseRouteUri()
    {
        $this->createMiddlewareInstance(true);

        $this->response->shouldReceive('getStatusCode')->andReturn(200);

        $this->agent->shouldReceive('startTransaction')
            ->once()
            ->andReturn($this->transaction);

        $this->middleware->handle($this->request, function () {
            return $this->response;
        });

        $data = $this->getTransactionData();

        $this->assertEquals('GET /path/script.php', $data['name']);
    }

    public function testTransactionTerminate()
    {
        $this->createMiddlewareInstance(false, '/\/health-check|^OPTIONS /');

        $this->agent->shouldReceive('stopTransaction')
            ->once()
            ->with('GET /ping');

        $this->agent->shouldReceive('collectEvents')
            ->once()
            ->with('GET /ping');

        $this->middleware->terminate($this->request);
    }

    public function testTransactionTerminateIgnored()
    {
        $this->agent->shouldNotReceive('stopTransaction');
        $this->agent->shouldNotReceive('collectEvents');

        $this->createMiddlewareInstance(false, '/\/health-check|^OPTIONS /');
        $this->middleware->terminate(Request::create('/posts', 'OPTIONS'));

        $this->createMiddlewareInstance(false, '/\/health-check|^OPTIONS /'); // reset the mock ->once() count
        $this->middleware->terminate(Request::create('/health-check', 'GET'));
    }

    public function testTransactionTerminateError()
    {
        $this->createMiddlewareInstance(false);

        $this->agent->shouldReceive('stopTransaction')
            ->once()
            ->andThrow('exception', 'error message');

        Log::shouldReceive('error')
            ->once()
            ->with('error message');

        $this->middleware->terminate($this->request);
    }

    private function getTransactionData(): array
    {
        $data = json_decode(json_encode($this->transaction), true);

        return $data['transaction'];
    }
}
