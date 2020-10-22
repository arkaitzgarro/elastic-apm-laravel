<?php

use AG\ElasticApmLaravel\ServiceProvider;

class ServiceProviderTest extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('elastic-apm-laravel.app.appName', 'Laravel');
    }

    public function testRegistersAgent(): void
    {
        $agent = app()->make(\AG\ElasticApmLaravel\Agent::class);

        $this->assertInstanceOf(\AG\ElasticApmLaravel\Agent::class, $agent);
    }
}
