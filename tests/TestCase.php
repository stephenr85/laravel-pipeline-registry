<?php

namespace Rushing\PipelineRegistry\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\PipelineRegistry\PipelineRegistryServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // `PopcornServiceProvider` is listed explicitly and must stay listed. Testbench does not
        // auto-discover, and `RegistryIndex` is auto-resolvable, so without this line every
        // `make(RegistryIndex::class)` hands back a THROWAWAY: `describe()` lands on an index nothing
        // else can see and the suite stays green over an empty keyspace (registry-kernel 27 D3).
        return [
            PopcornServiceProvider::class,
            PipelineRegistryServiceProvider::class,
        ];
    }
}
