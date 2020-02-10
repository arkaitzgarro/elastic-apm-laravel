<?php

namespace AG\ElasticApmLaravel\Events;

class StartMeasuring
{
    /** @var string */
    public $name;

    /** @var string */
    public $type;

    /** @var string|null */
    public $action;

    /** @var string|null */
    public $label;

    /** @var float|null */
    public $startTime;

    public function __construct(
        string $name,
        string $type = 'request',
        ?string $action = null,
        ?string $label = null,
        ?float $startTime = null
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->action = $action;
        $this->label = $label;
        $this->startTime = $startTime;
    }
}
