<?php

namespace AG\ElasticApmLaravel\Contracts;

use Illuminate\Support\Collection;

interface DataCollector
{
    public function collect(): Collection;

    public function getName(): string;

    public function registerEventListeners(): void;

    public function reset(): void;
}
