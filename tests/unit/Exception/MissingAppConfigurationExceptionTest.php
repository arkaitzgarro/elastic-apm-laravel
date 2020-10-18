<?php

use AG\ElasticApmLaravel\Exception\MissingAppConfigurationException;
use Codeception\Test\Unit;

class MissingAppConfigurationExceptionTest extends Unit
{
    /** @var MissingAppConfigurationException */
    private $exception;

    protected function _before(): void
    {
        $this->exception = new MissingAppConfigurationException();
    }

    public function testExceptionMessage(): void
    {
        self::assertEquals('Use AgentBuilder::withAppConfig() to provide a configuration object.', $this->exception->getMessage());
    }

    public function testExceptionDefaultCode(): void
    {
        self::assertEquals(0, $this->exception->getCode());
    }

    public function testExceptionProvidedCode(): void
    {
        $this->exception = new MissingAppConfigurationException(500);
        self::assertEquals(500, $this->exception->getCode());
    }
}
