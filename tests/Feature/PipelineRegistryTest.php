<?php

declare(strict_types=1);

use Rushing\PipelineRegistry\PipelineContext;
use Rushing\PipelineRegistry\PipelineRegistry;
use Rushing\PipelineRegistry\Stages\CopyFilesPipeline;
use Rushing\PipelineRegistry\Tests\Fixtures\EmitFileStage;

beforeEach(function () {
    $this->registry = app(PipelineRegistry::class);
    $this->tmp = sys_get_temp_dir().'/pipeline-registry-test-'.uniqid();
});

afterEach(function () {
    if (is_dir($this->tmp)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->tmp);
    }
});

it('is bound as a singleton', function () {
    expect(app(PipelineRegistry::class))->toBe(app('pipeline.registry'));
});

it('registers and runs a named pipeline over Illuminate\\Pipeline', function () {
    $this->registry->register('build', [
        [EmitFileStage::class, ['path' => 'types/tokens.d.ts', 'contents' => 'export type X = {};']],
    ]);

    $context = $this->registry->run('build', new PipelineContext);

    expect($context)->toBeInstanceOf(PipelineContext::class)
        ->and($context->files)->toHaveKey('types/tokens.d.ts')
        ->and($context->files['types/tokens.d.ts'])->toBe('export type X = {};')
        ->and($context->log)->toContain('EmitFileStage: collected types/tokens.d.ts');
});

it('flushes collected files to disk via the built-in CopyFilesPipeline', function () {
    $this->registry->register('emit', [
        [EmitFileStage::class, ['path' => 'a/b.txt', 'contents' => 'payload']],
        [CopyFilesPipeline::class, ['to' => $this->tmp]],
    ]);

    $this->registry->run('emit');

    expect(file_get_contents($this->tmp.'/a/b.txt'))->toBe('payload');
});

it('appends a stage at runtime via extend()', function () {
    $this->registry->register('emit', [
        [EmitFileStage::class, ['path' => 'one.txt', 'contents' => '1']],
    ]);

    $this->registry->extend('emit', [EmitFileStage::class, ['path' => 'two.txt', 'contents' => '2']]);

    $context = $this->registry->run('emit');

    expect($context->files)->toHaveKeys(['one.txt', 'two.txt']);
});

it('throws on an unknown pipeline name', function () {
    expect(fn () => $this->registry->run('nope'))
        ->toThrow(RuntimeException::class, 'Unknown pipeline [nope]');
});

it('discovers config/pipelines/*.php as {category}:{subName}', function () {
    $this->registry->mergePipelinesFrom(__DIR__.'/../Fixtures/pipelines');

    expect($this->registry->has('demo:alpha'))->toBeTrue()
        ->and($this->registry->has('demo:beta'))->toBeTrue()
        ->and($this->registry->stagesFor('demo:alpha'))
        ->toBe([EmitFileStage::class, CopyFilesPipeline::class]);
});
