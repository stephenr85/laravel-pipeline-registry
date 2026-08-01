<?php

namespace Rushing\PipelineRegistry\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\PipelineRegistry\PipelineRegistryServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PipelineRegistryServiceProvider::class,
        ];
    }
}
