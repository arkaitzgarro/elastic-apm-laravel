<?php

namespace AG\ElasticApmLaravel\Collectors;

class EventCounter
{
    public const EVENT_LIMIT = 1000;

    private $limit;
    private $count = 0;

    public function __construct(?int $limit = self::EVENT_LIMIT)
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
