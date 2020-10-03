<?php

namespace AG\ElasticApmLaravel\Exception;

/**
 * Base exception class all other package exceptions should extend.
 *
 * Class ElasticApmLaravelException
 */
class NoCurrentTransactionException extends \Exception
{
    public function __construct(int $code = 0, \Throwable $previous = null)
    {
        parent::__construct('No transaction is currently registered. Ensure a transaction is started.', $code, $previous);
    }
}
