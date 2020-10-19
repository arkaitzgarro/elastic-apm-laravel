<?php

use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use Codeception\Test\Unit;

class RequestStartTimeTest extends Unit
{
    /** @var RequestStartTime */
    private $startTime;

    protected function _before()
    {
        $this->startTime = new RequestStartTime(1000.0);
    }

    public function testMicroseconds()
    {
        $this->assertEquals(1000.0, $this->startTime->microseconds());
    }

    public function testStartTimeSetter()
    {
        $this->startTime->setStartTime(500.0);
        $this->assertEquals(500.0, $this->startTime->microseconds());
    }
}
