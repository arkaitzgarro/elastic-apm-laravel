<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\JobCollector;
use AG\ElasticApmLaravel\Collectors\RequestStartTime;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Nipwaayoni\Events\Transaction;
use Nipwaayoni\Exception\Transaction\UnknownTransactionException;

class JobCollectorTest extends Unit
{
    private const JOB_NAME = 'This\Is\A\Test\Job';
    // Use 4 backslashes to match a single backslash: https://stackoverflow.com/a/15369828
    private const JOB_IGNORE_PATTERN = "/\/health-check|This\\\\Is\\\\A\\\\Test\\\\Job/";

    /** @var Application */
    private $app;

    /** @var Dispatcher */
    private $dispatcher;

    /**
     * @var JobCollector
     */
    private $collector;

    /** @var Agent|\Mockery\LegacyMockInterface|\Mockery\MockInterface */
    private $agentMock;

    /** @var Job|\Mockery\LegacyMockInterface|\Mockery\MockInterface */
    private $jobMock;

    /** @var Transaction|\Mockery\LegacyMockInterface|\Mockery\MockInterface */
    private $transactionMock;

    /** @var Config|\Mockery\LegacyMockInterface|\Mockery\MockInterface */
    private $configMock;

    protected function _before()
    {
        $this->app = app(Application::class);
        $this->dispatcher = app(Dispatcher::class);

        $this->jobMock = Mockery::mock(Job::class);
        $this->transactionMock = Mockery::mock(Transaction::class);
        $this->agentMock = Mockery::mock(Agent::class);
        $this->configMock = Mockery::mock(Config::class);

        $requestStartTimeMock = Mockery::mock(RequestStartTime::class);
        $requestStartTimeMock->shouldReceive('setStartTime');
        $requestStartTimeMock->shouldReceive('microseconds')->andReturn(1000.0);

        $this->collector = new JobCollector(
            $this->app,
            $this->configMock,
            $requestStartTimeMock
        );
        $this->collector->useAgent($this->agentMock);
    }

    protected function _after(): void
    {
        // JobCollector registers these listeners and we need to start fresh each for every test
        $this->dispatcher->forget(JobProcessing::class);
        $this->dispatcher->forget(JobProcessed::class);
        $this->dispatcher->forget(JobFailed::class);
        $this->dispatcher->forget(JobExceptionOccurred::class);
    }

    protected function patternConfigReturn($configIgnore = null): void
    {
        $this->configMock->shouldReceive('get')
            ->once()
            ->with('elastic-apm-laravel.transactions.ignorePatterns')
            ->andReturn($configIgnore);
    }

    public function testCollectorName()
    {
        $this->assertEquals('job-collector', $this->collector->getName());
    }

    public function testJobProcessingListenerIgnored()
    {
        $this->patternConfigReturn(self::JOB_IGNORE_PATTERN);
        $this->jobMock->shouldReceive('resolveName')->once()->andReturn(self::JOB_NAME);
        $this->agentMock->shouldNotReceive('startTransaction');
        $this->agentMock->shouldNotReceive('getTransaction');

        $this->dispatcher->dispatch(new JobProcessing('test', $this->jobMock));
    }

    public function testJobProcessedListenerIgnored()
    {
        $this->patternConfigReturn(self::JOB_IGNORE_PATTERN);
        $this->jobMock->shouldReceive('resolveName')->once()->andReturn(self::JOB_NAME);
        $this->agentMock->shouldNotReceive('stopTransaction');
        $this->agentMock->shouldNotReceive('collectEvents');
        $this->agentMock->shouldNotReceive('send');

        $this->dispatcher->dispatch(new JobProcessed('test', $this->jobMock));
    }

    public function testJobFailedListenerIgnored()
    {
        $this->patternConfigReturn(self::JOB_IGNORE_PATTERN);
        $this->jobMock->shouldReceive('resolveName')->once()->andReturn(self::JOB_NAME);
        $this->agentMock->shouldNotReceive('getTransaction');
        $this->agentMock->shouldNotReceive('captureThrowable');
        $this->agentMock->shouldNotReceive('stopTransaction');

        $this->dispatcher->dispatch(new JobFailed('test', $this->jobMock, new Exception()));
    }

    public function testJobProcessingListener()
    {
        $this->patternConfigReturn();

        $this->jobMock
            ->shouldReceive('resolveName')
            ->once()
            ->andReturn(self::JOB_NAME);
        $this->jobMock
            ->shouldReceive('getJobId')
            ->once()
            ->andReturn('job_id');
        $this->jobMock
            ->shouldReceive('maxTries')
            ->once()
            ->andReturn(3);
        $this->jobMock
            ->shouldReceive('attempts')
            ->once()
            ->andReturn(1);
        $this->jobMock
            ->shouldReceive('getConnectionName')
            ->once()
            ->andReturn('sync');
        $this->jobMock
            ->shouldReceive('getQueue')
            ->once()
            ->andReturn('queue');
        $this->agentMock
            ->shouldReceive('startTransaction')
            ->once()
            ->withArgs(function ($job_name, $context, $start_time) {
                $this->assertEquals(self::JOB_NAME, $job_name);
                $this->assertEquals([], $context);
                $this->assertNotNull($start_time);

                return true;
            })
            ->andReturn($this->transactionMock);
        $this->agentMock
            ->shouldReceive('getTransaction')
            ->times(3)
            ->with(self::JOB_NAME)
            ->andReturn(null, $this->transactionMock, $this->transactionMock);

        $this->dispatcher->dispatch(new JobProcessing('test', $this->jobMock));
    }

    public function testJobProcessedListener()
    {
        $this->patternConfigReturn();

        $this->jobMock
            ->shouldReceive('resolveName')
            ->once()
            ->andReturn(self::JOB_NAME);
        $this->agentMock
            ->shouldReceive('getTransaction')
            ->once()
            ->with(self::JOB_NAME)
            ->andReturn($this->transactionMock);
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
        $this->patternConfigReturn();

        $exception = new Exception('fail');

        $this->jobMock
            ->shouldReceive('resolveName')
            ->once()
            ->andReturn(self::JOB_NAME);
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

    public function testJobFailedListenerWithMissingTransaction()
    {
        $this->patternConfigReturn();

        $exception = new Exception('fail');

        $this->jobMock
            ->shouldReceive('resolveName')
            ->once()
            ->andReturn(self::JOB_NAME);
        $this->agentMock
            ->shouldReceive('getTransaction')
            ->once()
            ->with(self::JOB_NAME)
            ->andThrow(new UnknownTransactionException());
        $this->agentMock
            ->shouldNotReceive('captureThrowable');
        $this->agentMock
            ->shouldNotReceive('stopTransaction');
        $this->agentMock
            ->shouldNotReceive('collectEvents');
        $this->agentMock
            ->shouldNotReceive('send');

        $this->dispatcher->dispatch(new JobFailed('test', $this->jobMock, $exception));
    }

    public function testJobProcessedExceptionOnSend()
    {
        $this->patternConfigReturn();

        $this->jobMock
            ->shouldReceive('resolveName')
            ->once()
            ->andReturn(self::JOB_NAME);
        $this->agentMock
            ->shouldReceive('getTransaction')
            ->once()
            ->with(self::JOB_NAME)
            ->andReturn($this->transactionMock);
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
        $this->patternConfigReturn();

        $this->agentMock
            ->shouldReceive('getTransaction')
            ->once()
            ->with(self::JOB_NAME)
            ->andReturn($this->transactionMock);
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
