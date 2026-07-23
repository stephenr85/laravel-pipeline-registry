<?php

declare(strict_types=1);

use Rushing\PipelineRegistry\Stages\CopyFilesPipeline;
use Rushing\PipelineRegistry\Tests\Fixtures\EmitFileStage;

/**
 * A category file keyed by sub-name — proves `demo.php` registers as
 * `demo:alpha` and `demo:beta` (category = filename, key = sub-name).
 */
return [
    'alpha' => [
        [EmitFileStage::class, ['path' => 'alpha.txt', 'contents' => 'A']],
        [CopyFilesPipeline::class, []],
    ],
    'beta' => [
        [EmitFileStage::class, ['path' => 'beta.txt', 'contents' => 'B']],
    ],
];
