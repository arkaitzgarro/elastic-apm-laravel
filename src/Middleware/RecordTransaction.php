<?php

namespace AG\ElasticApmLaravel\Middleware;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use Illuminate\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Nipwaayoni\Events\Transaction;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * This middleware will record a transaction from the moment the request hits the server, until the response is sent to the client.
 * This transaction will include:
 *   - The timestamp of the event.
 *   - A unique id, type, and name.
 *   - Data about the environment in which the event is recorded.
 *   - The stacktrace of executed code.
 *
 * The transaction will be send to Elastic server AFTER the reponse has been sent to the browser:
 * https://laravel.com/docs/5.8/middleware#terminable-middleware
 */
class RecordTransaction
{
    /**
     * Laravel resolves a new instance of the middleware to terminate the request, so
     * anything handle() needs to pass on to terminate() is stored on the request.
     */
    public const TRANSACTION_NAME_ATTRIBUTE = 'elastic-apm-laravel.transaction_name';
    public const REJECTED_REQUEST_ATTRIBUTE = 'elastic-apm-laravel.rejected_request';

    protected $agent;
    protected $config;
    protected $start_time;

    public function __construct(Agent $agent, Config $config, RequestStartTime $start_time)
    {
        $this->agent = $agent;
        $this->config = $config;
        $this->start_time = $start_time;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, \Closure $next)
    {
        try {
            $request->method();
        } catch (SuspiciousOperationException $exception) {
            // Symfony rejects the request because of an invalid method override. Record it, then
            // let the exception reach the application's handler as if this middleware was not here.
            $this->recordRejectedRequest($request);

            throw $exception;
        }

        $transaction_name = $this->getTransactionName($request);

        if ($this->shouldIgnoreTransaction($transaction_name)) {
            // Skip this middleware
            return $next($request);
        }

        // Start a new transaction
        $transaction = $this->startTransaction($transaction_name);
        $request->attributes->set(self::TRANSACTION_NAME_ATTRIBUTE, $transaction_name);

        // Execute the application logic
        $response = $next($request);

        if ($this->config->get('elastic-apm-laravel.transactions.useRouteUri')) {
            $transaction->setTransactionName($this->getRouteUriTransactionName($request));
        }

        $this->addMetadata($transaction, $request, $response);

        return $response;
    }

    public function addMetadata(Transaction $transaction, Request $request, Response $response): void
    {
        $transaction->setResponse([
            'finished' => true,
            'headers_sent' => true,
            'status_code' => $response->getStatusCode(),
        ]);

        $user = $request->user();
        $transaction->setUserContext([
            'id' => optional($user)->id,
            'ip' => $request->ip(),
            'user-agent' => $request->userAgent(),
        ]);

        $transaction->setMeta([
            'result' => $response->getStatusCode(),
            'type' => 'HTTP',
        ]);
    }

    public function terminate(Request $request, ?Response $response = null): void
    {
        $transaction_name = $request->attributes->get(self::TRANSACTION_NAME_ATTRIBUTE);

        if (null === $transaction_name) {
            // No transaction was started: the request was ignored, or never reached handle()
            return;
        }

        try {
            if (null !== $response && $request->attributes->get(self::REJECTED_REQUEST_ATTRIBUTE)) {
                // handle() never got a response for a rejected request
                $this->addMetadata($this->agent->getTransaction($transaction_name), $request, $response);
            }

            // Stop the transaction and measure the time
            $this->agent->stopTransaction($transaction_name);
            $this->agent->collectEvents($transaction_name);
        } catch (\Throwable $t) {
            Log::error($t->getMessage());
        }
    }

    protected function recordRejectedRequest(Request $request): void
    {
        $transaction_name = $this->getTransactionName($request);

        if ($this->shouldIgnoreTransaction($transaction_name)) {
            return;
        }

        $this->startTransaction($transaction_name);
        $request->attributes->set(self::TRANSACTION_NAME_ATTRIBUTE, $transaction_name);
        $request->attributes->set(self::REJECTED_REQUEST_ATTRIBUTE, true);
    }

    protected function shouldIgnoreTransaction(string $transaction_name): bool
    {
        $pattern = $this->config->get('elastic-apm-laravel.transactions.ignorePatterns');

        return $pattern && preg_match($pattern, $transaction_name);
    }

    /**
     * Start the transaction that will measure the request, application start up time,
     * DB queries, HTTP requests, etc.
     */
    protected function startTransaction(string $transaction_name): Transaction
    {
        return $this->agent->startTransaction(
            $transaction_name,
            [],
            $this->start_time->microseconds()
        );
    }

    protected function getTransactionName(Request $request): string
    {
        $uri = $request->path() ?? $this->getRequestUri();

        return $this->getRequestMethod($request) . ' ' . $this->normalizeUri($uri);
    }

    protected function getRouteUriTransactionName(Request $request): string
    {
        $route = $request->route();
        if ($route instanceof Route) {
            $uri = $route->uri();
        } else {
            $uri = $this->getRequestUri();
        }

        return $this->getRequestMethod($request) . ' ' . $this->normalizeUri($uri);
    }

    protected function getRequestMethod(Request $request): string
    {
        try {
            return $request->method();
        } catch (SuspiciousOperationException $exception) {
            // The method override is invalid, so use the method the client sent
            return $request->getRealMethod();
        }
    }

    protected function getRequestUri(): string
    {
        // Fallback to script file name, like index.php when URI is not provided
        return parse_url($_SERVER['REQUEST_URI'] ?? null, PHP_URL_PATH) ?? $_SERVER['SCRIPT_FILENAME'];
    }

    protected function normalizeUri(string $uri): string
    {
        // Fix leading /
        return '/' . trim($uri, '/');
    }
}
