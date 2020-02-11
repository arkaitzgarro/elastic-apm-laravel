<?php

namespace AG\ElasticApmLaravel\Facades;

use Illuminate\Support\Facades\Facade;

class ApmCollector extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'apm-collector';
    }
}
