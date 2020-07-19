<?php

namespace AG\ElasticApmLaravel\Events;

use JsonSerializable;
use PhilKra\Events\EventBean;
use PhilKra\Events\TraceableEvent;
use PhilKra\Helper\Encoding;
use PhilKra\Traits\Events\Stacktrace;

/**
 * Spans.
 *
 * @see https://www.elastic.co/guide/en/apm/server/master/span-api.html
 */
class LazySpan extends TraceableEvent implements JsonSerializable
{
    use Stacktrace;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var float
     */
    protected $start_time;

    /**
     * @var float
     */
    protected $duration = 0;

    /**
     * @var string
     */
    protected $action;

    /**
     * @var string
     */
    protected $type = 'request';

    /**
     * @var mixed array|null
     */
    protected $stacktrace = [];

    /**
     * @var int
     */
    protected $timestamp;

    /**
     * Extended Contexts such as Custom and/or User.
     *
     * @var array
     */
    protected $contexts = [
        'custom' => [],
        'labels' => [],
    ];

    /**
     * @param string. $name
     * @param array.  $context
     */
    public function __construct(string $name, EventBean $parent)
    {
        parent::__construct([]);

        $this->name = trim($name);
        $this->timestamp = $parent->getTimestamp();
        $this->setParent($parent);
    }

    /**
     * Get the Event Name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the Span's start time.
     *
     * @param string $action
     */
    public function setStartTime(float $start_time): void
    {
        $this->start_time = $start_time;
        $this->timestamp = $this->timestamp + $this->start_time * 1000;
    }

    /**
     * Set the Span's end time.
     *
     * @param string $action
     */
    public function setDuration(float $duration): void
    {
        $this->duration = $duration;
    }

    /**
     * Set the Span's Type.
     */
    public function setAction(string $action): void
    {
        $this->action = trim($action);
    }

    /**
     * Set the Spans' Action.
     */
    public function setType(string $type): void
    {
        $this->type = trim($type);
    }

    /**
     * Set the Spans' contexts.
     */
    public function setContext(array $contexts): void
    {
        $this->contexts = array_merge($this->contexts, $contexts);
    }

    /**
     * Set a complimentary Stacktrace for the Span.
     *
     * @see https://www.elastic.co/guide/en/apm/server/master/span-api.html
     */
    public function setStacktrace(array $stacktrace): void
    {
        $this->stacktrace = $stacktrace;
    }

    /**
     * Serialize Span Event.
     *
     * @see https://www.elastic.co/guide/en/apm/server/master/span-api.html
     */
    public function jsonSerialize(): array
    {
        return [
            'span' => [
                'id' => $this->getId(),
                'transaction_id' => $this->getParentId(),
                'trace_id' => $this->getTraceId(),
                'parent_id' => $this->getParentId(),
                'type' => Encoding::keywordField($this->type),
                'action' => Encoding::keywordField($this->action),
                'context' => $this->contexts,
                'start' => $this->start_time,
                'duration' => $this->duration,
                'name' => Encoding::keywordField($this->getName()),
                'stacktrace' => $this->stacktrace,
                'sync' => true,
                'timestamp' => $this->timestamp,
            ],
        ];
    }
}
