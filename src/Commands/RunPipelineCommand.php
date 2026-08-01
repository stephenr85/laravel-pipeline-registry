<?php

namespace Rushing\PipelineRegistry\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Rushing\PipelineRegistry\PipelineContext;
use Rushing\PipelineRegistry\PipelineRegistry;

class RunPipelineCommand extends Command
{
    protected $signature = 'pipelines:run {name : The registered pipeline name, e.g. resources:splicewire}';

    protected $description = 'Run a named pipeline over Illuminate\\Pipeline and report its stage trace.';

    public function handle(PipelineRegistry $registry): int
    {
        $name = $this->argument('name');

        if (! $registry->has($name)) {
            $this->error("Unknown pipeline [{$name}].");
            $this->line('Registered: '.(implode(', ', $registry->names()) ?: '(none)'));

            return self::FAILURE;
        }

        $this->info("Running pipeline [{$name}]...");

        try {
            $result = $registry->run($name, new PipelineContext);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result instanceof PipelineContext) {
            foreach ($result->log as $line) {
                $this->line('  '.$line);
            }

            $this->newLine();
            $this->info(sprintf('Emitted %d file(s).', count($result->files)));
        } else {
            $this->info('Pipeline finished.');
        }

        return self::SUCCESS;
    }
}
