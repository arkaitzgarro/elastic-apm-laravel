<?php
namespace AG\ElasticApmLaravel\Middleware;

use Closure;
use Throwable;
use PhilKra\Events\Transaction;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

use AG\ElasticApmLaravel\Agent;

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
    protected $agent;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Start a new transaction
        $transaction = $this->startTransaction($this->getTransactionName($request));

        // Execute the application logic
        $response = $next($request);

        if (config('elastic-apm-laravel.transactions.useRouteUri')) {
            $transaction->setTransactionName($this->getRouteUriTransactionName($request));
        }

        $this->addMetadata($transaction, $request, $response);

        return $response;
    }

    /**
     * Start the transaction that will measure the request, application start up time,
     * DB queries, HTTP requests, etc
     */
    protected function startTransaction(string $transaction_name): Transaction
    {
        return $this->agent->startTransaction(
            $transaction_name,
            [],
            $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)
        );
    }

    public function addMetadata(Transaction $transaction, Request $request, Response $response): void
    {
        $transaction->setResponse([
            'finished' => true,
            'headers_sent' => true,
            'status_code' => $response->getStatusCode(),
            'headers' => $this->formatHeaders($response->headers->all()),
        ]);

        $user = $request->user();
        $transaction->setUserContext([
            'id' => optional($user)->id,
            'ip' => $request->ip(),
            'user-agent' => $request->userAgent(),
        ]);

        $transaction->setMeta([
            'result' => $response->getStatusCode(),
            'type' => 'HTTP'
        ]);
    }

    public function terminate(Request $request): void
    {
        try {
            $transaction_name = $this->getTransactionName($request);

            // Stop the transaction and measure the time
            $this->agent->stopTransaction($transaction_name);
            $this->agent->sendTransaction($transaction_name);
        } catch (Throwable $t) {
            Log::error($t->getMessage());
        }
    }

    protected function getTransactionName(Request $request): string
    {
        $uri = $request->path() ?? $this->getRequestUri();
        return $request->method() . ' ' . $this->normalizeUri($uri);
    }

    protected function getRouteUriTransactionName(Request $request): string
    {
        $route = $request->route();
        if ($route instanceof Route) {
            $uri = $route->uri();
        } else {
            $uri = $this->getRequestUri();
        }

        return $request->method() . ' ' . $this->normalizeUri($uri);
    }

    protected function getRequestUri(): string
    {
        // Fallback to script file name, like index.php when URI is not provided
        return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? $_SERVER['SCRIPT_FILENAME'];
    }

    protected function normalizeUri(string $uri): string
    {
        // Fix leading /
        return '/' . trim($uri, '/');
    }

    protected function formatHeaders(array $headers): array
    {
        return collect($headers)->map(function ($values) {
            return head($values);
        })->toArray();
    }
}
