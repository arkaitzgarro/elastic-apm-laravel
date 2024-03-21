<?php

namespace AG\ElasticApmLaravel\Exception;

use Throwable;

class MissingAppConfigurationException extends ElasticApmLaravelException
{
    public function __construct(int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct('Use AgentBuilder::withAppConfig() to provide a configuration object.', $code, $previous);
    }
}
