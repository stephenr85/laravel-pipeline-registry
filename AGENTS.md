> You are in **rushing/laravel-pipeline-registry** — a thin, declarative registry over `Illuminate\Pipeline` for naming and running Laravel pipelines by name.

This is a standalone PHP Composer package. Pipelines are named arrays of `[[StageClass::class, [...options]]]` tuples, discovered per-package from `config/pipelines/*.php` (filename = category). The package owns the registry and executor wiring only — never the stages' domain logic. See `README.md` for usage.
