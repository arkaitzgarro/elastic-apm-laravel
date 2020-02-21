<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\JobCollector;
use Codeception\Test\Unit;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use PhilKra\Events\Transaction;

class JobCollectorTest extends Unit
{
    private const JOB_NAME = 'This\Is\A\Test\Job';
    private const REQUEST_START_TIME = 1000.0;

    /**
     * @var \AG\ElasticApmLaravel\Collectors\JobCollector
     */
    private $collector;

    private $agentMock;

    protected function _before()
    {
        $this->app = app(Application::class);
        $this->dispatcher = app(Dispatcher::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobMock = Mockery::mock(Job::class);
        $this->jobMock
            ->shouldReceive('resolveName')
            ->once()
            ->andReturn(self::JOB_NAME);

        $this->transactionMock = Mockery::mock(Transaction::class);

        $this->agentMock = Mockery::mock(Agent::class);
        $this->agentMock
            ->shouldReceive('getRequestStartTime')
            ->once()
            ->andReturn(self::REQUEST_START_TIME);

        $this->collector = new JobCollector($this->app, $this->agentMock);
    }

    protected function tearDown(): void
    {
        // JobCollector registers these listeners and we need to start fresh each for every test
        $this->dispatcher->forget(JobProcessing::class);
        $this->dispatcher->forget(JobProcessed::class);
        $this->dispatcher->forget(JobFailed::class);
        $this->dispatcher->forget(JobExceptionOccurred::class);
    }

    public function testCollectorName()
    {
        $this->assertEquals('job-collector', $this->collector->getName());
    }

    public function testJobProcessingListener()
    {
        $this->transactionMock
            ->shouldReceive('setMeta')
            ->once()
            ->with(['type' => 'job']);
        $this->agentMock
            ->shouldReceive('startTransaction')
            ->once()
            ->with(self::JOB_NAME, [], self::REQUEST_START_TIME)
            ->andReturn($this->transactionMock);
        $this->agentMock
            ->shouldReceive('getTransaction')
            ->once()
            ->with(self::JOB_NAME)
            ->andReturn($this->transactionMock);

        $this->dispatcher->dispatch(new JobProcessing('test', $this->jobMock));
    }

    public function testJobProcessedListener()
    {
        $this->agentMock
            ->shouldReceive('stopTransaction')
            ->once()
            ->with(self::JOB_NAME, ['result' => 200]);
        $this->agentMock
            ->shouldReceive('collectEvents')
            ->once()
            ->with(self::JOB_NAME);
        $this->agentMock
            ->shouldReceive('send')
            ->once();

        $this->dispatcher->dispatch(new JobProcessed('test', $this->jobMock));
    }

    public function testJobFailedListener()
    {
        $exception = new Exception('fail');

        $this->agentMock
            ->shouldReceive('getTransaction')
            ->once()
            ->with(self::JOB_NAME)
            ->andReturn($this->transactionMock);
        $this->agentMock
            ->shouldReceive('captureThrowable')
            ->once()
            ->with($exception, [], $this->transactionMock);
        $this->agentMock
            ->shouldReceive('stopTransaction')
            ->once()
            ->with(self::JOB_NAME, ['result' => 500]);
        $this->agentMock
            ->shouldReceive('collectEvents')
            ->once()
            ->with(self::JOB_NAME);
        $this->agentMock
            ->shouldReceive('send')
            ->once();

        $this->dispatcher->dispatch(new JobFailed('test', $this->jobMock, $exception));
    }

    public function testJobExceptionOccurredListener()
    {
        $exception = new Exception('occurred');

        $this->agentMock
            ->shouldReceive('getTransaction')
            ->once()
            ->with(self::JOB_NAME)
            ->andReturn($this->transactionMock);
        $this->agentMock
            ->shouldReceive('captureThrowable')
            ->once()
            ->with($exception, [], $this->transactionMock);
        $this->agentMock
            ->shouldReceive('stopTransaction')
            ->once()
            ->with(self::JOB_NAME, ['result' => 500]);
        $this->agentMock
            ->shouldReceive('collectEvents')
            ->once()
            ->with(self::JOB_NAME);
        $this->agentMock
            ->shouldReceive('send')
            ->once();

        $this->dispatcher->dispatch(new JobFailed('test', $this->jobMock, $exception));
    }

    public function testJobProcessedExceptionOnSend()
    {
        $this->agentMock
            ->shouldReceive('stopTransaction')
            ->once()
            ->with(self::JOB_NAME, ['result' => 200]);
        $this->agentMock
            ->shouldReceive('collectEvents')
            ->once()
            ->with(self::JOB_NAME);
        $this->agentMock
            ->shouldReceive('send')
            ->once()
            ->andThrow(new Exception('snowball'));

        Log::shouldReceive('error')
            ->once()
            ->with('snowball');

        $this->dispatcher->dispatch(new JobProcessed('test', $this->jobMock));
    }

    public function testJobProcessedSyncDriver()
    {
        $this->agentMock
            ->shouldReceive('stopTransaction')
            ->once()
            ->with(self::JOB_NAME, ['result' => 200]);
        $this->agentMock
            ->shouldReceive('collectEvents')
            ->once()
            ->with(self::JOB_NAME);
        $this->agentMock
            ->shouldNotReceive('send');

        $syncJobMock = Mockery::mock(SyncJob::class);
        $syncJobMock->shouldReceive('resolveName')
            ->once()
            ->andReturn(self::JOB_NAME);

        $this->dispatcher->dispatch(new JobProcessed('test', $syncJobMock));
    }
}
