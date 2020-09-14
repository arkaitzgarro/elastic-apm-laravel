<?php

use AG\ElasticApmLaravel\Contracts\VersionResolver;
use AG\ElasticApmLaravel\Services\ApmConfigService;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;

class ApmConfigServiceTest extends Unit
{
    private $appMock;

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

    public function testIsAgentDisabledCli()
    {
        $this->repoMock->shouldReceive('get')
            ->once()
            ->with('elastic-apm-laravel.active')
            ->andReturn(true);
        $this->repoMock->shouldReceive('get')
            ->once()
            ->with('elastic-apm-laravel.cli.active')
            ->andReturn(false);

        $this->assertTrue($this->configService->isAgentDisabled());
    }

    public function testIsAgentDisabledNot()
    {
        $this->repoMock->shouldReceive('get')
            ->once()
            ->with('elastic-apm-laravel.active')
            ->andReturn(true);
        $this->repoMock->shouldReceive('get')
            ->once()
            ->with('elastic-apm-laravel.cli.active')
            ->andReturn(true);

        $this->assertFalse($this->configService->isAgentDisabled());
    }

    public function testGetAgentConfig()
    {
        $this->appMock->shouldReceive('version')
            ->once()
            ->andReturn('3.2.1');
        $this->appMock->shouldReceive('bound')
            ->once()
            ->with(VersionResolver::class)
            ->andReturn(false);

        $packageConfig = require __DIR__ . '/../../../config/elastic-apm-laravel.php';
        $configService = new ApmConfigService(
            $this->appMock,
            new ConfigRepository(['elastic-apm-laravel' => $packageConfig])
        );

        $this->assertEquals(
            [
                'framework' => 'Laravel',
                'frameworkVersion' => '3.2.1',
                'active' => false,
                'httpClient' => [],
                'appName' => 'Laravel',
                'appVersion' => '',
                'env' => [
                    'DOCUMENT_ROOT',
                    'REMOTE_ADDR',
                ],
                'environment' => 'development',
                'serverUrl' => 'http://127.0.0.1:8200',
                'secretToken' => null,
                'hostname' => gethostname(),
            ],
            $configService->getAgentConfig()
        );
    }

    public function testVersionResolver()
    {
        $this->appMock->shouldReceive('version')
            ->once()
            ->andReturn('3.2.1');
        $this->appMock->shouldReceive('bound')
            ->once()
            ->with(VersionResolver::class)
            ->andReturn(true);
        $this->appMock->shouldReceive('make')
            ->once()
            ->with(VersionResolver::class)
            ->andReturn(new VersionResolverDouble());

        $packageConfig = require __DIR__ . '/../../../config/elastic-apm-laravel.php';
        $configService = new ApmConfigService(
            $this->appMock,
            new ConfigRepository(['elastic-apm-laravel' => $packageConfig])
        );

        $this->assertEquals(
            '1.2.3',
            $configService->getAgentConfig()['appVersion']
        );
    }
}

class VersionResolverDouble implements VersionResolver
{
    public function getVersion(): string
    {
        return '1.2.3';
    }
}
