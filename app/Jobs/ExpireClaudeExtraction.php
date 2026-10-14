<?php

namespace App\Jobs;

use App\Enums\ExtractionStatus;
use App\Models\ExtractionRun;
use App\Support\RemittanceWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Delayed safety net: a routine run that never calls back is marked failed so the user can retry.
 */
class ExpireClaudeExtraction implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(private readonly int $runId)
    {
    }

    public function handle(): void
    {
        $run = ExtractionRun::find($this->runId);

        if ($run !== null && $run->status === ExtractionStatus::Processing) {
            RemittanceWriter::fail($run, 'Processing took too long. Please try again.');
        }
    }
}
