<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Middleware\RecordTransaction;
use Codeception\Test\Unit;
use DMS\PHPUnitExtensions\ArraySubset\Assert;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PhilKra\Events\Transaction;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class RecordTransactionTest extends Unit
{
    /**
     * @var AG\ElasticApmLaravel\Agent
     */
    private $agent;

    /**
     * @var Illuminate\Config\Repository
     */
    private $config;

    /**
     * @var Illuminate\Http\Request
     */
    private $request;

    /**
     * @var Illuminate\Http\Response
     */
    private $response;

    private $middleware;

    /**
     * @var PhilKra\Events\Transaction
     */
    private $transaction;

    protected function _before()
    {
        $this->agent = Mockery::mock(Agent::class);
        $this->transaction = Mockery::mock(Transaction::class)->makePartial();
        $this->request = Request::create('/ping', 'GET');
        $this->response = Mockery::mock(Response::class)->makePartial();
        $this->response->headers = new ResponseHeaderBag();

        Config::shouldReceive('get')
            ->once()
            ->with('elastic-apm-laravel.transactions.useRouteUri')
            ->andReturn(false);

        $this->middleware = new RecordTransaction($this->agent, $this->config);
    }

    public function testStartTransaction()
    {
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
        $this->agent->shouldReceive('startTransaction')
            ->once()
            ->andReturn($this->transaction);

        $this->response->shouldReceive('getStatusCode')
            ->once()
            ->andReturn(200);

        $this->middleware->handle($this->request, function () {
            return $this->response;
        });

        $this->assertEquals(200, $this->transaction->getMetaResult());
        $this->assertEquals('HTTP', $this->transaction->getMetaType());
    }

    public function testTransactionContext()
    {
        $this->agent->shouldReceive('startTransaction')
            ->once()
            ->andReturn($this->transaction);

        $this->response->shouldReceive('getStatusCode')
            ->once()
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
        ], $context['request']['response']);

        $this->assertEquals([
            'id' => null,
            'ip' => '127.0.0.1',
            'user-agent' => 'Symfony/3.X',
        ], $context['user']);
    }

    public function testTransactionTerminate()
    {
        $this->agent->shouldReceive('stopTransaction')
            ->once()
            ->with('GET /ping');

        $this->agent->shouldReceive('collectEvents')
            ->once()
            ->with('GET /ping');

        $this->middleware->terminate($this->request);
    }

    public function testTransactionTerminateError()
    {
        $this->agent->shouldReceive('stopTransaction')
            ->once()
            ->andThrow('exception', 'error message');

        Log::shouldReceive('error')
            ->once()
            ->with('error message');

        $this->middleware->terminate($this->request);
    }
}
