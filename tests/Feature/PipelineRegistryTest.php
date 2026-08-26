<?php

use Rushing\PipelineRegistry\PipelineContext;
use Rushing\PipelineRegistry\PipelineRegistry;
use Rushing\PipelineRegistry\Stages\CopyFilesPipeline;
use Rushing\PipelineRegistry\Tests\Fixtures\EmitFileStage;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\RegistryIndex;

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

it('shares ONE RegistryIndex, so describe() does not land on a throwaway', function () {
    // The harness tripwire (registry-kernel 27 D3). `RegistryIndex` is auto-resolvable, so a testbench
    // harness that forgets `PopcornServiceProvider` gets a fresh one per `make()` and every assertion
    // below would pass over an index nothing else can see.
    expect(app(RegistryIndex::class))->toBe(app(RegistryIndex::class));
});

it('is described into the shared RegistryIndex under pipelines', function () {
    $index = app(RegistryIndex::class);

    expect(array_map('strval', $index->keys()))->toContain('pipelines')
        ->and($index->resolve('pipelines'))->toBe($this->registry);
});

it('routes a pipeline key through the index back to this registry', function () {
    $this->registry->register('build:assets', [[EmitFileStage::class, ['path' => 'x', 'contents' => '']]]);

    expect(app(RegistryIndex::class)->routeTo(Key::parse('pipelines.build:assets')))
        ->toBe($this->registry);
});

it('round-trips register() -> resolve() through both vocabularies', function () {
    // The port's own words in, the contract's words out, and back again. `{category}:{subName}` is ONE
    // segment spelled with a colon (Key charset, ticket 30 D9), so `build:assets` addresses absolutely
    // as `pipelines.build:assets` with no string surgery.
    $this->registry->register('build:assets', [[EmitFileStage::class, ['path' => 'x', 'contents' => 'v']]]);

    expect($this->registry->has('build:assets'))->toBeTrue()
        ->and($this->registry->has('pipelines.build:assets'))->toBeTrue()
        ->and($this->registry->names())->toContain('build:assets')
        ->and(array_map('strval', $this->registry->keys()))->toContain('pipelines.build:assets')
        ->and($this->registry->resolve('build:assets'))
        ->toBe([[EmitFileStage::class, ['path' => 'x', 'contents' => 'v']]])
        ->and($this->registry->stagesFor('build:assets'))->toBe([EmitFileStage::class]);
});

it('throws RegistryMiss from resolve() but keeps its own enumerating exception on run()', function () {
    // Sweep amendment A13. The contract binds `resolve()`; it does not bind the port's sugar, and
    // `run()`'s message lists the registered names — information `RegistryMiss` cannot carry. Declining
    // the exception change on `run()` is what kept every consumer untouched.
    expect(fn () => $this->registry->resolve('nope'))->toThrow(RegistryMiss::class)
        ->and(fn () => $this->registry->run('nope'))
        ->toThrow(RuntimeException::class, 'Unknown pipeline [nope]');
});

it('refuses an entry that is not an ordered list of stage tuples', function () {
    expect(fn () => $this->registry->register('bad', EmitFileStage::class))
        ->toThrow(InvalidArgumentException::class, 'ORDERED LIST');
});

it('supersedes a re-registered name in place, keeping its slot in keys()', function () {
    // Sweep amendment A4 read the other way: supersession APPENDED where array assignment held the
    // slot. Registry-kernel 62 made it an override IN PLACE, so `keys()` now matches what the array
    // did. `names()` sorts, so the caller-facing enumeration was stable either way — both are pinned
    // here rather than left to chance.
    $this->registry->register('alpha', [[EmitFileStage::class, ['path' => 'a', 'contents' => 'a']]]);
    $this->registry->register('beta', [[EmitFileStage::class, ['path' => 'b', 'contents' => 'b']]]);
    $this->registry->register('alpha', [[EmitFileStage::class, ['path' => 'a2', 'contents' => 'a2']]]);

    expect(array_map('strval', $this->registry->keys()))
        ->toBe(['pipelines.alpha', 'pipelines.beta'])
        ->and($this->registry->names())->toBe(['alpha', 'beta'])
        ->and($this->registry->stagesFor('alpha'))->toBe([EmitFileStage::class])
        ->and($this->registry->run('alpha')->files)->toHaveKey('a2');
});
