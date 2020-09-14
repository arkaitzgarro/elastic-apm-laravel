<?php

use AG\ElasticApmLaravel\Events\LazySpan;
use Codeception\Test\Unit;
use PhilKra\Events\EventBean;

class LazySpanTest extends Unit
{
    /**
     * Parent transaction for LazySpan.
     *
     * @var \PhilKra\Events\EventBean
     */
    private $parent;

    /**
     * LazySpan instance.
     *
     * @var \AG\ElasticApmLaravel\Events\LazySpan
     */
    private $lazy_span;

    protected function _before()
    {
        // Create a parent transaction
        $this->parent = new EventBean([]);
        $this->parent->setTraceId('unique-trace-id');

        $this->lazy_span = new LazySpan(' lazy span ', $this->parent);
    }

    public function testInstanceCreation()
    {
        $this->assertEquals('lazy span', $this->lazy_span->getName());
    }

    public function testSetStartTimeMethod()
    {
        $lazy_span_decoded = json_decode(json_encode($this->lazy_span));
        $timestamp = $lazy_span_decoded->span->timestamp;
        $this->lazy_span->setStartTime(1);
        $lazy_span_decoded = json_decode(json_encode($this->lazy_span));

        $this->assertEquals($timestamp + 1000, $lazy_span_decoded->span->timestamp);
    }

    public function testSetDurationMethod()
    {
        $this->lazy_span->setDuration(1000);
        $lazy_span_decoded = json_decode(json_encode($this->lazy_span));

        $this->assertEquals(1000, $lazy_span_decoded->span->duration);
    }

    public function testSetActionMethod()
    {
        $this->lazy_span->setAction('db_query');
        $lazy_span_decoded = json_decode(json_encode($this->lazy_span));

        $this->assertEquals('db_query', $lazy_span_decoded->span->action);
    }

    public function testSetTypeMethod()
    {
        $this->lazy_span->setType('db');
        $lazy_span_decoded = json_decode(json_encode($this->lazy_span));

        $this->assertEquals('db', $lazy_span_decoded->span->type);
    }

    public function testSetContextMethod()
    {
        $this->lazy_span->setContext([
            'custom' => [
                'user' => 1,
            ],
            'labels' => [
                'test',
            ],
        ]);
        $lazy_span_decoded = json_decode(json_encode($this->lazy_span));
        $context = $lazy_span_decoded->span->context;

        $this->assertEquals(1, $context->custom->user);
        $this->assertEquals(['test'], $context->labels);
    }

    public function testSetStacktraceMethod()
    {
        $this->lazy_span->setStacktrace([
            'file' => 'code',
        ]);
        $lazy_span_decoded = json_decode(json_encode($this->lazy_span));

        $this->assertEquals('code', $lazy_span_decoded->span->stacktrace->file);
    }

    public function testJsonSerialize()
    {
        $lazy_span_decoded = json_decode(json_encode($this->lazy_span))->span;

        $this->assertIsString($lazy_span_decoded->id);
        $this->assertIsString($lazy_span_decoded->transaction_id);
        $this->assertEquals('unique-trace-id', $lazy_span_decoded->trace_id);
        $this->assertEquals($this->parent->getId(), $lazy_span_decoded->parent_id);
        $this->assertEquals('request', $lazy_span_decoded->type);
        $this->assertNull($lazy_span_decoded->action);
        $this->assertEquals([], $lazy_span_decoded->context->custom);
        $this->assertEquals([], $lazy_span_decoded->context->labels);
        $this->assertNull($lazy_span_decoded->start);
        $this->assertEquals(0, $lazy_span_decoded->duration);
        $this->assertEquals('lazy span', $lazy_span_decoded->name);
        $this->assertEquals([], $lazy_span_decoded->stacktrace);
        $this->assertTrue($lazy_span_decoded->sync);
        $this->assertIsNumeric($lazy_span_decoded->timestamp);
    }
}
