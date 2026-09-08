<?php

namespace Rushing\PipelineRegistry;

use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use RuntimeException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Symfony\Component\Finder\Finder;

/**
 * A named, runtime-mutable registry over {@see Illuminate\Pipeline\Pipeline}.
 *
 * Laravel already owns the executor; the only thing this package invents is the
 * *registry* — a place to name a pipeline once (from a plain config array of
 * `[[StageClass::class, [...options]]]` tuples) and run it by name from anywhere.
 * Stages are ordinary Laravel pipes: `handle($passable, Closure $next)`, so any
 * stage is a standard, reusable Illuminate pipe with nothing package-specific.
 *
 * Pipelines are discovered per-package from `config/pipelines/*.php` via
 * {@see mergePipelinesFrom()}: the filename is the *category* and each top-level
 * key in the file is a *sub-name*, composed as `"{category}:{subName}"`. Two
 * packages contributing the same category never edit one shared file — each
 * ships its own `config/pipelines/{category}.php` and the registry accretes them.
 *
 * ## It is also a `Registry`, natively (registry-kernel ticket 38)
 *
 * A {@see BasicRegistry} held as a FIELD, never a base class — the private `[name => tuples]` array is
 * gone and every read delegates. `register()` widens contravariantly, so the package's own
 * `register('build:assets', [[Stage::class, []]])` door and the kernel's four-argument one are the same
 * method.
 *
 * `pipelines.{name}` addresses a whole pipeline. Its stages are an ordered positional list:
 * `extend()` appends stages, and the same class may appear more than once.
 */
#[IsRegistry(
    root: 'pipelines',
    entryType: 'mixed',
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    description: 'Named stage chains discovered per package from config/pipelines/*.php. Select a pipeline by its category:subName key, then run its ordered stage tuples. The colon belongs to one key segment. Stages have no individual keys and may repeat.',
    order: 64,
)]
class PipelineRegistry implements Gated, Registry
{
    private BasicRegistry $pipelines;

    public function __construct(private Container $container)
    {
        $this->pipelines = BasicRegistry::for($this);
    }

    /**
     * Register (or replace) a named pipeline from its stage-tuple definition.
     *
     * WIDENED contravariantly from {@see Registry::register()} rather than shadowing it, so every
     * historical `register('build:assets', [[Stage::class, []]])` caller keeps working unchanged while
     * the kernel's own `register(RegistryKey|string, mixed, ?string, ?string)` door also opens.
     *
     * The entry MUST be a list of stage tuples. Refused loudly rather than wrapped, on
     * {@see BasicRegistry::for()}'s precedent: a bare class-string would run, silently, as a
     * one-stage pipeline nobody wrote.
     *
     * @param  array<int, array{0: class-string, 1?: array<string, mixed>}>|mixed  $entry
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if (! is_array($entry) || ! array_is_list($entry)) {
            throw new InvalidArgumentException(sprintf(
                'A `%s` entry is an ORDERED LIST of `[stage class, options]` tuples; got %s for `%s`. '
                .'Stages are positional entries and have no individual keys.',
                self::class,
                get_debug_type($entry),
                (string) $key,
            ));
        }

        $this->pipelines->register(
            $key,
            array_map(
                fn (array $tuple): array => [$tuple[0], $tuple[1] ?? []],
                $entry,
            ),
            $by,
            $ability,
        );

        return $this;
    }

    /**
     * Append a stage to an existing (or new) pipeline at runtime — the "the
     * registry can be interacted with dynamically" affordance.
     *
     * @param  array{0: class-string, 1?: array<string, mixed>}  $stage
     */
    public function extend(string $name, array $stage): static
    {
        return $this->register(
            $name,
            [...$this->stagesTuples($name), [$stage[0], $stage[1] ?? []]],
            by: 'extend',
        );
    }

    /**
     * Discover every `config/pipelines/*.php` in a directory and merge each into
     * the registry. Filename (sans `.php`) is the category; each top-level key in
     * the returned array is a sub-name, registered as `"{category}:{subName}"`.
     *
     * This is the deep-merge {@see ServiceProvider::mergeConfigFrom()}
     * cannot do: that is shallow and single-file, whereas pipelines arrive one
     * file per category across many packages.
     */
    public function mergePipelinesFrom(string $directory): static
    {
        if (! is_dir($directory)) {
            return $this;
        }

        foreach (Finder::create()->files()->name('*.php')->in($directory)->sortByName() as $file) {
            $category = $file->getBasename('.php');
            $definitions = require $file->getRealPath();

            if (! is_array($definitions) || $definitions === []) {
                continue;
            }

            // A category file is either flat (a list of stage-tuples — one
            // unnamed pipeline whose name IS the category) or keyed by sub-name
            // (each value a stage-tuple list, registered as "category:subName").
            if (array_is_list($definitions)) {
                $this->register($category, $definitions);

                continue;
            }

            foreach ($definitions as $subName => $stages) {
                $this->register("{$category}:{$subName}", $stages);
            }
        }

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->pipelines->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->pipelines->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->pipelines->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->pipelines->matches($key);
    }

    public function keys(): array
    {
        return $this->pipelines->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->pipelines->unfiltered();
    }

    /**
     * {@see Gated} — the index pushes the host's authorizer down here on both edges (ticket 20 D6).
     * No pipeline declares an `ability` today, and an ungated entry short-circuits before the
     * authorizer is consulted, so this costs nothing until a host closes the surface.
     */
    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->pipelines->authorizeWith($authorizer);

        return $this;
    }

    /**
     * The registered pipeline names, as callers spelled them — `relativeKeys()`, not `keys()`, because
     * keys go relative in and absolute out (ticket 20 D2) and this list is caller-facing.
     *
     * Sorted for the human-facing pipeline list (`pipelines:list`). Sorting also
     * makes the list stable under supersession, which APPENDS rather than holding an entry's slot.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        $names = $this->pipelines->relativeKeys();
        sort($names);

        return $names;
    }

    /**
     * Resolve a pipeline's tuples into stage instances and run the passable
     * through them over {@see Illuminate\Pipeline\Pipeline}. A fresh
     * {@see PipelineContext} is used when no passable is supplied.
     */
    public function run(string $name, mixed $passable = null): mixed
    {
        if (! $this->has($name)) {
            throw new RuntimeException(
                "Unknown pipeline [{$name}]. Registered: ".(implode(', ', $this->names()) ?: '(none)').'.'
            );
        }

        $passable ??= new PipelineContext;

        $stages = array_map(
            fn (array $tuple): object => $this->container->make($tuple[0], ['options' => $tuple[1]]),
            $this->stagesTuples($name),
        );

        return (new Pipeline($this->container))
            ->send($passable)
            ->through($stages)
            ->thenReturn();
    }

    /**
     * The resolved stage class list for a name — for `pipelines:list` output and
     * assertions.
     *
     * @return array<int, class-string>
     */
    public function stagesFor(string $name): array
    {
        return array_map(fn (array $tuple): string => $tuple[0], $this->stagesTuples($name));
    }

    /**
     * The stored tuple list for a name, or `[]` when nothing is registered under it.
     *
     * `tryResolve()` rather than `resolve()` on purpose: {@see run()} keeps this package's own
     * `RuntimeException`, whose message ENUMERATES the registered names — information
     * `RegistryMiss` cannot carry. `resolve()` still throws `RegistryMiss` for a contract caller
     * (sweep amendment A13: the contract binds `resolve()`, not the port's sugar).
     *
     * @return array<int, array{0: class-string, 1: array<string, mixed>}>
     */
    private function stagesTuples(string $name): array
    {
        /** @var array<int, array{0: class-string, 1: array<string, mixed>}>|null $tuples */
        $tuples = $this->pipelines->tryResolve($name);

        return $tuples ?? [];
    }
}
