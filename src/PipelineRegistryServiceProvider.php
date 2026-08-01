<?php

namespace Rushing\PipelineRegistry;

use Illuminate\Support\ServiceProvider;
use Rushing\PipelineRegistry\Commands\ListPipelinesCommand;
use Rushing\PipelineRegistry\Commands\RunPipelineCommand;

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
        if ($this->app->runningInConsole()) {
            $this->commands([
                RunPipelineCommand::class,
                ListPipelinesCommand::class,
            ]);
        }
    }
}
