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
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
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

        $this->response->shouldReceive('getStatusCode')->andReturn(200);
        $this->agent->shouldReceive('startTransaction')
            ->once()
            ->andReturn($this->transaction);

        $this->agent->shouldReceive('stopTransaction')
            ->once()
            ->with('GET /ping');

        $this->agent->shouldReceive('collectEvents')
            ->once()
            ->with('GET /ping');

        $this->middleware->handle($this->request, function () {
            return $this->response;
        });
        $this->middleware->terminate($this->request, $this->response);
    }

    public function testTransactionTerminateIgnored()
    {
        $this->agent->shouldNotReceive('startTransaction');
        $this->agent->shouldNotReceive('stopTransaction');
        $this->agent->shouldNotReceive('collectEvents');

        foreach ([Request::create('/posts', 'OPTIONS'), Request::create('/health-check', 'GET')] as $request) {
            $this->createMiddlewareInstance(false, '/\/health-check|^OPTIONS /'); // reset the mock ->once() count
            $this->middleware->handle($request, function () {
                return $this->response;
            });
            $this->middleware->terminate($request, $this->response);
        }
    }

    public function testTransactionTerminateError()
    {
        $this->createMiddlewareInstance(false);

        $this->response->shouldReceive('getStatusCode')->andReturn(200);
        $this->agent->shouldReceive('startTransaction')
            ->once()
            ->andReturn($this->transaction);

        $this->agent->shouldReceive('stopTransaction')
            ->once()
            ->andThrow('exception', 'error message');

        Log::shouldReceive('error')
            ->once()
            ->with('error message');

        $this->middleware->handle($this->request, function () {
            return $this->response;
        });
        $this->middleware->terminate($this->request, $this->response);
    }

    public function testTerminateWithoutStartedTransaction()
    {
        // An exception thrown before this middleware runs means handle() never starts a transaction
        $this->config = Mockery::mock(Config::class);
        $this->middleware = new RecordTransaction($this->agent, $this->config, $this->requestStartTimeMock);

        $this->agent->shouldNotReceive('stopTransaction');
        $this->agent->shouldNotReceive('collectEvents');
        Log::shouldReceive('error')->never();

        $this->middleware->terminate($this->request, $this->response);
    }

    public function testValidMethodOverrideNamesTransaction()
    {
        $this->createMiddlewareInstance(false);

        $request = $this->createMethodOverrideRequest('PUT');

        $this->response->shouldReceive('getStatusCode')->andReturn(200);
        $this->agent->shouldReceive('startTransaction')
            ->once()
            ->with('PUT /ping', [], 1000.0)
            ->andReturn($this->transaction);

        $this->agent->shouldReceive('stopTransaction')
            ->once()
            ->with('PUT /ping');

        $this->agent->shouldReceive('collectEvents')
            ->once()
            ->with('PUT /ping');

        $this->middleware->handle($request, function () {
            return $this->response;
        });
        $this->middleware->terminate($request, $this->response);
    }

    public function testInvalidMethodOverrideIsRethrown()
    {
        $this->createMiddlewareInstance(false);

        $request = $this->createMethodOverrideRequest('/../../etc/passwd');

        $this->agent->shouldReceive('startTransaction')
            ->once()
            ->with('POST /ping', [], 1000.0)
            ->andReturn($this->transaction);

        $next_called = false;

        try {
            $this->middleware->handle($request, function () use (&$next_called) {
                $next_called = true;

                return $this->response;
            });
            $this->fail('The SuspiciousOperationException must reach the application\'s exception handler');
        } catch (SuspiciousOperationException $exception) {
            $this->assertEquals('Invalid HTTP method override.', $exception->getMessage());
        }

        $this->assertFalse($next_called);
    }

    public function testInvalidMethodOverrideTerminate()
    {
        $this->createMiddlewareInstance(false);

        $request = $this->createMethodOverrideRequest('/../../etc/passwd');

        $this->agent->shouldReceive('startTransaction')
            ->once()
            ->andReturn($this->transaction);

        $this->agent->shouldReceive('getTransaction')
            ->once()
            ->with('POST /ping')
            ->andReturn($this->transaction);

        $this->agent->shouldReceive('stopTransaction')
            ->once()
            ->with('POST /ping');

        $this->agent->shouldReceive('collectEvents')
            ->once()
            ->with('POST /ping');

        Log::shouldReceive('error')->never();

        try {
            $this->middleware->handle($request, function () {
                return $this->response;
            });
        } catch (SuspiciousOperationException $exception) {
            // The application renders this exception into the response terminate() receives
        }

        $this->response->shouldReceive('getStatusCode')->andReturn(400);
        $this->middleware->terminate($request, $this->response);

        $data = $this->getTransactionData();

        $this->assertEquals(400, $data['result']);
        $this->assertEquals('HTTP', $data['type']);
        $this->assertEquals(400, $data['context']['response']['status_code']);
    }

    public function testInvalidMethodOverrideIgnored()
    {
        $this->createMiddlewareInstance(false, '/^POST \/ping/');

        $request = $this->createMethodOverrideRequest('/../../etc/passwd');

        $this->agent->shouldNotReceive('startTransaction');
        $this->agent->shouldNotReceive('stopTransaction');
        $this->agent->shouldNotReceive('collectEvents');

        $this->expectException(SuspiciousOperationException::class);

        try {
            $this->middleware->handle($request, function () {
                return $this->response;
            });
        } finally {
            $this->middleware->terminate($request, $this->response);
        }
    }

    private function createMethodOverrideRequest(string $method): Request
    {
        // The header is always honoured, while the _method parameter needs a global opt-in
        $request = Request::create('/ping', 'POST');
        $request->headers->set('X-HTTP-METHOD-OVERRIDE', $method);

        return $request;
    }

    private function getTransactionData(): array
    {
        $data = json_decode(json_encode($this->transaction), true);

        return $data['transaction'];
    }
}
