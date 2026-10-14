<?php

namespace App\Jobs;

use App\Support\ExtractionStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Delayed safety net: a routine run that never calls back is marked failed so the user can retry.
 */
class ExpireClaudeExtraction implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(private readonly string $uuid)
    {
    }

    public function handle(): void
    {
        $entry = ExtractionStore::get($this->uuid);

        if ($entry !== null && $entry['status'] === 'processing') {
            ExtractionStore::update($this->uuid, [
                'status' => 'failed',
                'callback_token' => null,
                'error_message' => 'Processing took too long. Please try again.',
            ]);
        }
    }
}
