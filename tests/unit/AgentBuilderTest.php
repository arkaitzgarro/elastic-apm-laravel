<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\AgentBuilder;
use AG\ElasticApmLaravel\Collectors\SpanCollector;
use AG\ElasticApmLaravel\Exception\MissingAppConfigurationException;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as Config;

class AgentBuilderTest extends Unit
{
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
        $this->expectException(MissingAppConfigurationException::class);
        $this->builder->build();
    }

    public function testBuildAgent()
    {
        /** @var Agent */
        $agent = $this->builder
            ->withAppConfig(new Config(['serviceName' => 'Test']))
            ->build();

        self::assertEquals(Agent::class, get_class($agent));
    }

    public function testBuildAgentWithCollectors()
    {
        $this->collector
            ->shouldReceive('useAgent')
                ->once()
            ->shouldReceive('getName')
                ->times(2)
            ->andReturn('span-collector');

        /** @var Agent */
        $agent = $this->builder
            ->withAppConfig(new Config(['serviceName' => 'Test']))
            ->withEventCollectors(collect([$this->collector]))
            ->build();

        self::assertEquals('span-collector', $agent->getCollector('span-collector')->getName());
    }
}
