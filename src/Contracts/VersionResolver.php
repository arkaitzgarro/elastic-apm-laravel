<?php
namespace AG\ElasticApmLaravel\Contracts;

interface VersionResolver
{
    public function getVersion(): string;
}
