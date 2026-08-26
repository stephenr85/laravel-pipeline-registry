<?php

namespace Rushing\PipelineRegistry;

use Illuminate\Support\ServiceProvider;
use Rushing\PipelineRegistry\Commands\ListPipelinesCommand;
use Rushing\PipelineRegistry\Commands\RunPipelineCommand;
use Rushing\Popcorn\Registries\RegistryIndex;

/**
 * Binds the {@see PipelineRegistry} as a singleton and registers the
 * `pipelines:run` / `pipelines:list` console commands. Consuming packages add
 * their own pipelines from their own providers via
 * {@see PipelineRegistry::mergePipelinesFrom()} — this provider ships the
 * mechanism, never a domain pipeline.
 */
class PipelineRegistryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PipelineRegistry::class, fn ($app) => new PipelineRegistry($app));
        $this->app->alias(PipelineRegistry::class, 'pipeline.registry');
    }

    public function boot(): void
    {
        // Owners describe DOWN into the index from their own provider, after the binding exists and
        // before any consumer's `mergePipelinesFrom()` — declaring and indexing are two acts
        // (registry-kernel ticket 21 D1), and a declared-but-never-described root reads as conformant
        // while `popcorn:registries` holds nothing. `describe()` takes the object, not its entries, so
        // a consumer registering later is still enumerable through it.
        $this->app->make(RegistryIndex::class)->describe(
            $this->app->make(PipelineRegistry::class),
            by: self::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                RunPipelineCommand::class,
                ListPipelinesCommand::class,
            ]);
        }
    }
}
