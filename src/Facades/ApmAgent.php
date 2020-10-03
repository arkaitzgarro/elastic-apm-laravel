<?php

namespace AG\ElasticApmLaravel\Facades;

use Illuminate\Support\Facades\Facade;

class ApmAgent extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'apm-agent';
    }
}
