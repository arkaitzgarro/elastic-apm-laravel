<?php

use AG\ElasticApmLaravel\Exception\NoCurrentTransactionException;
use Codeception\Test\Unit;

class NoCurrentTransactionExceptionTest extends Unit
{
    /** @var NoCurrentTransactionException */
    private $exception;

    protected function _before(): void
    {
        $this->exception = new NoCurrentTransactionException();
    }

    public function testExceptionMessage(): void
    {
        self::assertEquals('No transaction is currently registered. Ensure a transaction is started.', $this->exception->getMessage());
    }

    public function testExceptionDefaultCode(): void
    {
        self::assertEquals(0, $this->exception->getCode());
    }

    public function testExceptionProvidedCode(): void
    {
        $this->exception = new NoCurrentTransactionException(500);
        self::assertEquals(500, $this->exception->getCode());
    }
}
