<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Services\ApmAgentService;
use Codeception\Test\Unit;
use Illuminate\Foundation\Application;

class ApmAgentServiceTest extends Unit
{
    /** @var ApmAgentService */
    private $agentService;

    private $appMock;
    private $agentMock;

    protected function setUp(): void
    {
        parent::setup();

        $this->appMock = Mockery::mock(Application::class);
        $this->agentMock = Mockery::mock(Agent::class);

        $this->agentService = new ApmAgentService(
            $this->appMock,
            $this->agentMock
        );
    }

    public function testGetsCurrentTransaction()
    {
        $transaction = Mockery::mock(\Nipwaayoni\Events\Transaction::class);

        $this->agentMock->shouldReceive('currentTransaction')
            ->once()
            ->andReturn($transaction);

        $this->assertSame($transaction, $this->agentService->getCurrentTransaction());
    }

    public function testAddsTraceparentHeaderToRequest()
    {
        $request = Mockery::mock(\Psr\Http\Message\RequestInterface::class);

        $transaction = Mockery::mock(\Nipwaayoni\Events\Transaction::class);

        $this->agentMock->shouldReceive('currentTransaction')
            ->once()
            ->andReturn($transaction);

        $transaction->shouldReceive('addTraceHeaderToRequest')
            ->once()
            ->with($request)
            ->andReturn($request);

        $this->assertSame($request, $this->agentService->addTraceParentHeader($request));
    }
}
