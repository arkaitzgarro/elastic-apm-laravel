<?php

namespace AG\ElasticApmLaravel\Exception;

class NoCurrentTransactionException extends ElasticApmLaravelException
{
    public function __construct(int $code = 0, \Throwable $previous = null)
    {
        parent::__construct('No transaction is currently registered. Ensure a transaction is started.', $code, $previous);
    }
}
