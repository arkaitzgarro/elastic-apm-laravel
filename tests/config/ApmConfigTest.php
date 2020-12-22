<?php

namespace AG\Tests\config;

class ApmConfigTest extends \Codeception\Test\Unit
{
    private $configFilePath = __DIR__ . '/../../config/elastic-apm-laravel.php';

    /** @var array */
    private $config;

    protected function _after()
    {
        // Make sure all environment variables are unset after every spec
        putenv('APM_ACTIVE');
        putenv('APM_APPNAME');
        putenv('APM_APPVERSION');
        putenv('APM_ENVIRONMENT');
        putenv('APM_SERVERURL');
        putenv('APM_SECRETTOKEN');
        putenv('APM_USEROUTEURI');
        putenv('APM_MAXTRACEITEMS');
        putenv('APM_BACKTRACEDEPTH');
        putenv('APM_QUERYLOG');
        putenv('APM_THRESHOLD');

        putenv('ELASTIC_APM_ENABLED');
        putenv('ELASTIC_APM_SERVICE_NAME');
        putenv('ELASTIC_APM_SERVER_URL');
        putenv('ELASTIC_APM_SERVICE_VERSION');
        putenv('ELASTIC_APM_SECRET_TOKEN');
        putenv('ELASTIC_APM_HOSTNAME');
        putenv('ELASTIC_APM_STACK_TRACE_LIMIT');
        putenv('ELASTIC_APM_TRANSACTION_SAMPLE_RATE');
    }

    public function testDefaultValues()
    {
        $this->config = include $this->configFilePath;

        $this->assertTrue($this->config['active']);
        $this->assertEquals('http://127.0.0.1:8200', $this->config['server']['serverUrl']);

        // app block
        $this->assertEquals('Laravel', $this->config['app']['appName']);
        $this->assertEquals('', $this->config['app']['appVersion']);

        // env block
        $this->assertEquals(['DOCUMENT_ROOT', 'REMOTE_ADDR'], $this->config['env']['env']);
        $this->assertEquals('development', $this->config['env']['environment']);

        // server block
        $this->assertNull($this->config['server']['secretToken']);

        // transactions block
        $this->assertTrue($this->config['transactions']['useRouteUri']);

        // spans block
        $this->assertEquals(1000, $this->config['spans']['maxTraceItems']);
        $this->assertEquals(25, $this->config['spans']['querylog']['enabled']);
        $this->assertEquals(200, $this->config['spans']['querylog']['threshold']);
        $this->assertEquals(25, $this->config['spans']['backtraceDepth']);
    }

    public function testAppConfigEnvVariables()
    {
        putenv('APM_ACTIVE=false');
        putenv('APM_APPNAME="Codeception App"');
        putenv('APM_APPVERSION="1.0.0"');
        $this->config = include $this->configFilePath;

        $this->assertFalse($this->config['active']);
        $this->assertEquals('Codeception App', $this->config['app']['appName']);
        $this->assertEquals('1.0.0', $this->config['app']['appVersion']);
    }

    public function testAppNameSpecialCharacters()
    {
        putenv('APM_APPNAME="Codeception?App"');
        $this->config = include $this->configFilePath;

        $this->assertEquals('Codeception-App', $this->config['app']['appName']);
    }

    public function testEnvConfigVariables()
    {
        putenv('APM_ENVIRONMENT="production"');
        $this->config = include $this->configFilePath;

        $this->assertEquals('production', $this->config['env']['environment']);
    }

    public function testServerConfigEnvVariables()
    {
        putenv('APM_SERVERURL="https://cloud.elastic.io:8200"');
        putenv('APM_SECRETTOKEN="super_secret_value"');
        $this->config = include $this->configFilePath;

        $this->assertEquals('https://cloud.elastic.io:8200', $this->config['server']['serverUrl']);
        $this->assertEquals('super_secret_value', $this->config['server']['secretToken']);
    }

    public function testTransactionsConfigEnvVariables()
    {
        putenv('APM_USEROUTEURI=false');
        $this->config = include $this->configFilePath;

        $this->assertFalse($this->config['transactions']['useRouteUri']);
    }

    public function testSpansConfigEnvVariables()
    {
        putenv('APM_MAXTRACEITEMS=10');
        putenv('APM_BACKTRACEDEPTH=10');
        putenv('APM_QUERYLOG="auto"');
        putenv('APM_THRESHOLD=50');
        $this->config = include $this->configFilePath;

        $this->assertEquals(10, $this->config['spans']['maxTraceItems']);
        $this->assertEquals(10, $this->config['spans']['backtraceDepth']);
        $this->assertEquals('auto', $this->config['spans']['querylog']['enabled']);
        $this->assertEquals(50, $this->config['spans']['querylog']['threshold']);
    }

    /**
     * @dataProvider elasticApmVariableChecks
     */
    public function testSupportsElasticApmEnvironmentVariables(string $setVariable, string $configPath, $expected): void
    {
        putenv($setVariable);

        $this->config = include $this->configFilePath;

        $this->assertEquals($expected, $this->getConfigPathValue($configPath));
    }

    public function elasticApmVariableChecks(): array
    {
        return [
            'ELASTIC_APM_ENABLED true' => ['ELASTIC_APM_ENABLED=true', 'active', true],
            'ELASTIC_APM_ENABLED false' => ['ELASTIC_APM_ENABLED=false', 'active', false],
            'ELASTIC_APM_SERVICE_NAME' => ['ELASTIC_APM_SERVICE_NAME=TestService', 'app.appName', 'TestService'],
            'ELASTIC_APM_SERVER_URL' => ['ELASTIC_APM_SERVER_URL=https://example.com', 'server.serverUrl', 'https://example.com'],
            'ELASTIC_APM_SERVICE_VERSION' => ['ELASTIC_APM_SERVICE_VERSION=8.0', 'app.appVersion', '8.0'],
            'ELASTIC_APM_SECRET_TOKEN' => ['ELASTIC_APM_SECRET_TOKEN=abc123', 'server.secretToken', 'abc123'],
            'ELASTIC_APM_HOSTNAME' => ['ELASTIC_APM_HOSTNAME=node1.example.com', 'server.hostname', 'node1.example.com'],
            'ELASTIC_APM_STACK_TRACE_LIMIT' => ['ELASTIC_APM_STACK_TRACE_LIMIT=10', 'spans.backtraceDepth', '10'],
            'ELASTIC_APM_TRANSACTION_SAMPLE_RATE' => ['ELASTIC_APM_TRANSACTION_SAMPLE_RATE=.5', 'agent.transactionSampleRate', '.5'],
        ];
    }

    /**
     * @dataProvider apmVariablePreferenceChecks
     */
    public function testPrefersApmEnvironmentVariables(string $apmVariable, string $elasticVariable, string $configPath, $expected): void
    {
        putenv($apmVariable);
        putenv($elasticVariable);

        $this->config = include $this->configFilePath;

        $this->assertEquals($expected, $this->getConfigPathValue($configPath));
    }

    public function apmVariablePreferenceChecks(): array
    {
        return [
            'APM_ACTIVE true' => ['APM_ACTIVE=true', 'ELASTIC_APM_ENABLED=false', 'active', true],
            'APM_ACTIVE false' => ['APM_ACTIVE=false', 'ELASTIC_APM_ENABLED=true', 'active', false],
            'APM_APPNAME' => ['APM_APPNAME=ApmTestService', 'ELASTIC_APM_SERVICE_NAME=TestService', 'app.appName', 'ApmTestService'],
            'APM_APPVERSION' => ['APM_APPVERSION=7.0', 'ELASTIC_APM_SERVICE_VERSION=8.0', 'app.appVersion', '7.0'],
            'APM_SERVERURL' => ['APM_SERVERURL=https://example2.com', 'ELASTIC_APM_SERVER_URL=https://example.com', 'server.serverUrl', 'https://example2.com'],
            'APM_SECRETTOKEN' => ['APM_SECRETTOKEN=xyz789', 'ELASTIC_APM_SECRET_TOKEN=abc123', 'server.secretToken', 'xyz789'],
            'APM_BACKTRACEDEPTH' => ['APM_BACKTRACEDEPTH=5', 'ELASTIC_APM_STACK_TRACE_LIMIT=10', 'spans.backtraceDepth', '5'],
        ];
    }

    private function getConfigPathValue(string $path)
    {
        $keys = explode('.', $path);
        $config = $this->config;

        while ($key = array_shift($keys)) {
            $config = $config[$key];
        }

        return $config;
    }
}
