<?php

namespace AG\ElasticApmLaravel\Collectors;

class EventCounter
{
    private $limit;
    private $count = 0;

    public function __construct(?int $limit = 1000)
    {
        $this->limit = $limit;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function increment(): void
    {
        ++$this->count;
    }

    public function reachedLimit(): bool
    {
        return $this->count >= $this->limit;
    }

    public function reset(): void
    {
        $this->count = 0;
    }
}
