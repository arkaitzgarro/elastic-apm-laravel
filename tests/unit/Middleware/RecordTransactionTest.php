<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Middleware\RecordTransaction;
use Codeception\Test\Unit;
use DMS\PHPUnitExtensions\ArraySubset\Assert;
use Illuminate\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Nipwaayoni\Events\Transaction;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class RecordTransactionTest extends Unit
{
    /**
     * @var AG\ElasticApmLaravel\Agent
     */
    private $agent;

    /**
     * @var Illuminate\Http\Request
     */
    private $request;

    /**
     * @var Illuminate\Http\Response
     */
    private $response;

    /**
     * @var AG\ElasticApmLaravel\Middleware\RecordTransaction
     */
    private $middleware;

    /**
     * @var Nipwaayoni\Events\Transaction
     */
    private $transaction;

    protected function _before()
    {
        $this->agent = Mockery::mock(Agent::class);
        $this->transaction = Mockery::mock(Transaction::class)->makePartial();
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

        $this->middleware = new RecordTransaction(
            $this->agent,
            $this->config,
        );
    }

    public function testStartTransaction()
    {
        $this->createMiddlewareInstance(false);

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

        $this->assertEquals(200, $this->transaction->getMetaResult());
        $this->assertEquals('HTTP', $this->transaction->getMetaType());
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

        $context = $this->transaction->getContext();

        Assert::assertArraySubset([
            'finished' => true,
            'headers_sent' => true,
            'status_code' => 200,
            'headers' => [],
        ], $context['response']);

        $this->assertEquals([
            'id' => null,
            'ip' => '127.0.0.1',
            'user-agent' => 'Symfony/3.X',
        ], $context['user']);
    }

    public function testUseRouteUri()
    {
        $this->createMiddlewareInstance(true);

        $this->agent->shouldReceive('startTransaction')
            ->once()
            ->andReturn($this->transaction);

        $this->transaction->shouldReceive('setTransactionName')
            ->once()
            ->with('GET /path/script.php');

        $this->middleware->handle($this->request, function () {
            return $this->response;
        });
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
}
