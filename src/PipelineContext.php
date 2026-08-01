<?php

namespace Rushing\PipelineRegistry;

/**
 * The default passable that travels through a named pipeline's stages.
 *
 * The registry is domain-neutral, so the context carries only the two things
 * every emit-style pipeline needs: an accumulating map of {@see $files} (relative
 * path => contents) that a downstream writer flushes to disk, and a {@see $log}
 * trace of what each stage did. {@see $data} is a free-form bag for any
 * cross-stage handoff a pipeline invents. Stages requiring domain parameters
 * (a scope name, a source path) take them from their own tuple options, not from
 * here — that keeps the context reusable across unrelated pipelines.
 *
 * A pipeline is free to send its own passable instead; this is the batteries-
 * included default {@see PipelineRegistry::run()} constructs when none is given.
 */
class PipelineContext
{
    /** @var array<string, string> relative path => file contents, collected across stages */
    public array $files = [];

    /** @var array<int, string> human-readable trace of what each stage did */
    public array $log = [];

    /** @var array<string, mixed> free-form cross-stage handoff bag */
    public array $data = [];

    public function note(string $line): void
    {
        $this->log[] = $line;
    }

    public function put(string $relativePath, string $contents): void
    {
        $this->files[$relativePath] = $contents;
    }
}
