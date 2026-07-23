<?php

declare(strict_types=1);

namespace Rushing\PipelineRegistry\Commands;

use Illuminate\Console\Command;
use Rushing\PipelineRegistry\PipelineRegistry;

class ListPipelinesCommand extends Command
{
    protected $signature = 'pipelines:list';

    protected $description = 'List every registered pipeline and its resolved stages.';

    public function handle(PipelineRegistry $registry): int
    {
        $names = $registry->names();

        if ($names === []) {
            $this->warn('No pipelines registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['Pipeline', 'Stages'],
            array_map(fn (string $name): array => [
                $name,
                implode(' → ', array_map(
                    fn (string $class): string => class_basename($class),
                    $registry->stagesFor($name),
                )),
            ], $names),
        );

        return self::SUCCESS;
    }
}
