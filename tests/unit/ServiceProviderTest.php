<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\EventCounter;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use AG\ElasticApmLaravel\Facades\ApmAgent;
use AG\ElasticApmLaravel\Facades\ApmCollector;
use AG\ElasticApmLaravel\Middleware\RecordTransaction;
use AG\ElasticApmLaravel\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Nipwaayoni\Events\Transaction;
use Orchestra\Testbench\TestCase;

class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('elastic-apm-laravel.app.appName', 'Laravel');
    }

    public function testRegistersAgent(): void
    {
        $agent = app()->make(Agent::class);

        $this->assertInstanceOf(Agent::class, $agent);
    }

    public function testRegistersRequestStartTime(): void
    {
        $startTime = app()->make(RequestStartTime::class);

        $this->assertInstanceOf(RequestStartTime::class, $startTime);
        $this->assertGreaterThan(0, $startTime->microseconds());
    }

    public function testRegistersEventCounter(): void
    {
        $eventCounter = app()->make(EventCounter::class);

        $this->assertInstanceOf(EventCounter::class, $eventCounter);
        $this->assertEquals(EventCounter::EVENT_LIMIT, $eventCounter->limit());
    }

    public function testRegistersNonHttpCollectors(): void
    {
        $collectors = iterator_to_array(app()->tagged('event-collector')->getIterator());

        $this->assertEquals(5, count($collectors));

        $this->assertEquals('query-collector', $collectors[0]->getName());
        $this->assertEquals('command-collector', $collectors[1]->getName());
        $this->assertEquals('scheduled-task-collector', $collectors[2]->getName());
        $this->assertEquals('job-collector', $collectors[3]->getName());
        $this->assertEquals('span-collector', $collectors[4]->getName());
    }

    public function testRegistersFacades(): void
    {
        ApmCollector::startMeasure('test-span', 'test', 'measure', 'My test span');
        ApmCollector::startMeasure('test-span', 'test', 'measure', 'My custom span');

        $agent = app()->make(Agent::class);
        $agent->setCurrentTransaction(Mockery::mock(Transaction::class));
        $this->assertInstanceOf(Transaction::class, ApmAgent::getCurrentTransaction());
    }

    public function testRegistersMiddleware(): void
    {
        $kernel = app()->make(Kernel::class);
        $this->assertTrue($kernel->hasMiddleware(RecordTransaction::class));
    }
}
