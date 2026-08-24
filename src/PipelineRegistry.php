<?php

namespace Rushing\PipelineRegistry;

use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
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
 */
#[IsRegistry(
    root: 'pipelines',
    of: 'named stage chains keyed `{category}:{subName}`, discovered per-package from '
        .'`config/pipelines/*.php` — a place to name an Illuminate pipeline once and run it by name',
    arity: RegistryArity::PickOne,
    entryType: 'mixed',
    onDuplicate: OnDuplicate::Supersede,
    note: 'Two things a reader will otherwise get wrong. (1) The key `{category}:{subName}` is ONE '
        .'segment spelled with a colon, legal since registry-kernel ticket 30 D9 widened `Key`\'s '
        .'charset — `:` joins parts of one identity here, it does not separate two. (2) Arity is '
        .'PickOne, NOT the ComposeMany the retired host-side descriptor claimed: a read engages exactly '
        .'one entry (the named pipeline); the stage chain composed inside it is not a second set of '
        .'entries. That is the under-described second level ticket 15 D10 found and ticket 47 is '
        .'chartered to express as a list. `entryType` is `mixed` because an entry is a list of '
        .'`[stage class, options]` tuples, not an object.',
    order: 64,
)]
class PipelineRegistry
{
    /** @var array<string, array<int, array{0: class-string, 1: array<string, mixed>}>> */
    private array $pipelines = [];

    public function __construct(private Container $container) {}

    /**
     * Register (or replace) a named pipeline from its stage-tuple definition.
     *
     * @param  array<int, array{0: class-string, 1?: array<string, mixed>}>  $stages
     */
    public function register(string $name, array $stages): static
    {
        $this->pipelines[$name] = array_map(
            fn (array $tuple): array => [$tuple[0], $tuple[1] ?? []],
            $stages,
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
        $this->pipelines[$name][] = [$stage[0], $stage[1] ?? []];

        return $this;
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

    public function has(string $name): bool
    {
        return isset($this->pipelines[$name]);
    }

    /** @return array<int, string> */
    public function names(): array
    {
        $names = array_keys($this->pipelines);
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
            $this->pipelines[$name],
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
        return array_map(fn (array $tuple): string => $tuple[0], $this->pipelines[$name] ?? []);
    }
}
