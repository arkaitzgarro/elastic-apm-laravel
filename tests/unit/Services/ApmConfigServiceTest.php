<?php

use AG\ElasticApmLaravel\Services\ApmConfigService;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;

class ApmConfigServiceTest extends Unit
{
    private const SPAN_NAME = 'test-measure';

    private $appMock;
    private $configMock;
    private $eventsMock;

    private $collectorService;

    protected function setUp(): void
    {
        parent::setup();

        $this->appMock = Mockery::mock(Application::class);
        $this->repoMock = Mockery::mock(ConfigRepository::class);

        $this->configService = new ApmConfigService(
            $this->appMock,
            $this->repoMock
        );
    }

    public function testGet()
    {
        $this->repoMock->shouldReceive('get')
            ->once()
            ->with('mykey', 'myvalue')
            ->andReturn('sample');

        $this->assertEquals('sample', $this->configService->get('mykey', 'myvalue'));
    }

    public function testGetDefault()
    {
        $this->repoMock->shouldReceive('get')
            ->once()
            ->with('mykey', null)
            ->andReturn('sample');

        $this->assertEquals('sample', $this->configService->get('mykey'));
    }

    public function testIsAgentDisabled()
    {
        $this->repoMock->shouldReceive('get')
            ->once()
            ->with('elastic-apm-laravel.active')
            ->andReturn(false);

        $this->assertTrue($this->configService->isAgentDisabled());
    }

    /*
     * More tests to come, gathering some initial feedback first.
     */
}
