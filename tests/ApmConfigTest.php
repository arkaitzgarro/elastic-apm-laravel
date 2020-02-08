<?php

namespace AG\Tests;

class ApmConfigTest extends \Codeception\Test\Unit
{

    public function testDefaultValues()
    {
        $config = include(__DIR__ . '/../config/elastic-apm-laravel.php');

        $this->assertTrue($config['active']);

        // app block
        $this->assertEquals('Laravel', $config['app']['appName']);
        $this->assertEquals('', $config['app']['appVersion']);

        // env block
        $this->assertEquals(['DOCUMENT_ROOT', 'REMOTE_ADDR'], $config['env']['env']);
        $this->assertEquals('development', $config['env']['environment']);

        // httpClient block
        $this->assertEquals([], $config['httpClient']);

        // server block
        $this->assertEquals('http://127.0.0.1:8200', $config['server']['serverUrl']);
        $this->assertNull($config['server']['secretToken']);

        // transactions block
        $this->assertTrue($config['transactions']['useRouteUri']);

        // spans block
        $this->assertEquals(1000, $config['spans']['maxTraceItems']);
        $this->assertEquals(25, $config['spans']['backtraceDepth']);
        $this->assertEquals(25, $config['spans']['querylog']['enabled']);
        $this->assertEquals(200, $config['spans']['querylog']['threshold']);
    }
}
