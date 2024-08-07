<?php

use AG\ElasticApmLaravel\Helpers\StacktraceExtractor;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as Config;

class StacktraceExtractorTest extends Unit
{
    private Config $config;

    protected function _before(): void
    {
        $this->config = Mockery::mock(Config::class);
    }

    public function testGetStacktraceDisabledRenderSourceReturnsEmptyArray()
    {
        $this->config->shouldReceive('get')
            ->with('elastic-apm-laravel.spans.renderSource', Mockery::any())
            ->andReturn(false);

        $stacktrace = StacktraceExtractor::getStacktrace($this->config);

        $this->assertEquals([], $stacktrace);
    }
}
