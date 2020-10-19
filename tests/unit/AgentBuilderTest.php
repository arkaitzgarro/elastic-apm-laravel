<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\AgentBuilder;
use AG\ElasticApmLaravel\Exception\MissingAppConfigurationException;
use AG\ElasticApmLaravel\Collectors\SpanCollector;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as Config;

class AgentBuilderTest extends Unit
{
    use Codeception\AssertThrows;

    /** @var AgentBuilder */
    private $builder;

    /** @var SpanCollector */
    private $collector;

    protected function _before(): void
    {
        $this->builder = new AgentBuilder();
        $this->collector = Mockery::mock(SpanCollector::class);
    }

    public function testMissingConfigurationException(): void
    {
        self::assertThrows(MissingAppConfigurationException::class, function () {
            $this->builder->build();
        });
    }

    public function testBuildAgent()
    {
        /** @var Agent */
        $agent = $this->builder
            ->withAppConfig(new Config([ 'serviceName' => 'Test' ]))
            ->build();

        self::assertEquals(Agent::class, get_class($agent));
    }

    public function testBuildAgentWithCollectors()
    {
        $this->collector
            ->shouldReceive('useAgent')
            ->shouldReceive('getName')
            ->andReturn('span-collector');

        /** @var Agent */
        $agent = $this->builder
            ->withAppConfig(new Config([ 'serviceName' => 'Test' ]))
            ->withEventCollectors(collect([$this->collector]))
            ->build();

        self::assertEquals('span-collector', $agent->getCollector('span-collector')->getName());
    }
}
