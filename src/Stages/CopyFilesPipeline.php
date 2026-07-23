<?php

declare(strict_types=1);

namespace Rushing\PipelineRegistry\Stages;

use Closure;
use Rushing\PipelineRegistry\PipelineContext;

/**
 * The one built-in stage: flush the context's accumulated {@see PipelineContext::$files}
 * (relative path => contents) to a target directory. It is the terminal writer
 * an emit-style pipeline ends with — every upstream stage just *collects* files
 * into the context; this one puts them on disk.
 *
 * Options:
 *   - `to`    (string, required) absolute target directory.
 *   - `clean` (bool)   when true, unlink the target dir's tracked files first.
 */
class CopyFilesPipeline
{
    /** @param  array{to?: string, clean?: bool}  $options */
    public function __construct(protected array $options = []) {}

    public function handle(PipelineContext $context, Closure $next): PipelineContext
    {
        $target = $this->options['to'] ?? null;

        if ($target === null) {
            $context->note('CopyFilesPipeline: SKIPPED — no [to] target configured.');

            return $next($context);
        }

        $target = rtrim($target, '/');

        foreach ($context->files as $relative => $contents) {
            $destination = $target.'/'.ltrim($relative, '/');
            $directory = dirname($destination);

            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($destination, $contents);
            $context->note("CopyFilesPipeline: wrote {$relative} (".strlen($contents).' bytes)');
        }

        return $next($context);
    }
}
