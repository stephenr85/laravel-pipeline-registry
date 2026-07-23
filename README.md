# rushing/laravel-pipeline-registry

A thin, declarative registry over `Illuminate\Pipeline`. You **name** a pipeline
once — as a plain array of `[[StageClass::class, [...options]]]` tuples — and
**run it by name** from anywhere. Laravel owns the executor; the only thing this
package invents is the registry.

Stages are ordinary Laravel pipes:

```php
public function handle($passable, Closure $next) { /* ... */ return $next($passable); }
```

so any stage is a standard, reusable Illuminate pipe with nothing package-specific.

## Registering pipelines

Each package ships its own `config/pipelines/{category}.php` — no two packages
ever edit one shared file. The **filename is the category**; each top-level key
is a **sub-name**, composed as `"{category}:{subName}"`:

```php
// config/pipelines/resources.php
return [
    'splicewire' => [
        [TransformTypesStage::class, ['scope' => 'splicewire', /* ... */]],
        [CopyFilesPipeline::class, ['to' => base_path('../_resources')]],
    ],
];
// → registered as "resources:splicewire"
```

Wire it from your provider's `boot()`:

```php
$this->app->make(PipelineRegistry::class)
    ->mergePipelinesFrom(__DIR__.'/../config/pipelines');
```

A flat (list) file registers one pipeline whose name *is* the category.

## Running

```php
$context = app(PipelineRegistry::class)->run('resources:splicewire');
```

or from the console:

```
php artisan pipelines:list
php artisan pipelines:run resources:splicewire
```

## The context

`run()` sends a fresh `PipelineContext` when you pass no passable. It carries an
accumulating `files` map (relative path => contents), a `log` trace, and a
free-form `data` bag. The built-in **`CopyFilesPipeline`** stage flushes
`files` to its `to` directory — the terminal writer an emit-style pipeline ends
with. Send your own passable to `run()` to use a different shape.

## Runtime mutation

`register()` / `extend()` / `has()` / `names()` mutate and inspect the registry
at runtime — a package can append a stage to a pipeline another package declared.
