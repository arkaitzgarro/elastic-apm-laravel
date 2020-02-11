<?php

namespace AG\ElasticApmLaravel\Events;

class StopMeasuring
{
    /** @var string */
    public $name;

    /** @var array */
    public $params;

    public function __construct(
        string $name,
        array $params = []
    ) {
        $this->name = $name;
        $this->params = $params;
    }
}
