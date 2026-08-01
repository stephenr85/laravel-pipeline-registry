<?php

namespace Rushing\PipelineRegistry\Tests\Fixtures;

use Closure;
use Rushing\PipelineRegistry\PipelineContext;

/**
 * A minimal upstream stage: collect one named file into the context. Proves the
 * standard Laravel pipe signature and that options flow in from the tuple.
 */
class EmitFileStage
{
    /** @param  array{path?: string, contents?: string}  $options */
    public function __construct(protected array $options = []) {}

    public function handle(PipelineContext $context, Closure $next): PipelineContext
    {
        $path = $this->options['path'] ?? 'emitted.txt';
        $contents = $this->options['contents'] ?? 'hello';

        $context->put($path, $contents);
        $context->note("EmitFileStage: collected {$path}");

        return $next($context);
    }
}
