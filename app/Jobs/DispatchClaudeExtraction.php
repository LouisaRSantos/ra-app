<?php

namespace App\Jobs;

use App\Claude\RoutineClient;
use App\Claude\RoutineQuotaExceeded;
use App\Enums\ExtractionStatus;
use App\Models\ExtractionRun;
use App\Support\RemittanceWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fires the Claude routine once. The result arrives later via ClaudeExtractionController::callback.
 */
class DispatchClaudeExtraction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Extra attempts exist only for quota (429) releases; every other failure is final (no auto re-fire).
    public int $tries = 10;

    public function __construct(private readonly int $runId)
    {
    }

    public function handle(RoutineClient $client): void
    {
        $run = ExtractionRun::with('remittance')->find($this->runId);

        if ($run === null || $run->status !== ExtractionStatus::Pending) {
            return;
        }

        $uuid = $run->remittance->uuid;
        $token = Str::random(64);

        $imageUrls = [];
        foreach ($run->remittance->files()->pluck('position') as $position) {
            $imageUrls[] = URL::temporarySignedRoute('claude.image', now()->addMinutes(30), ['uuid' => $uuid, 'index' => $position]);
        }

        // Token hash and "processing" are stored before firing, so a fast callback can never race the write.
        RemittanceWriter::markProcessing($run, ExtractionRun::hashToken($token));

        try {
            $fired = $client->fire([
                'document_id' => $uuid,
                'image_urls' => $imageUrls,
                'callback_url' => route('claude.callback', ['uuid' => $uuid]),
                'callback_token' => $token,
            ]);
        } catch (RoutineQuotaExceeded $e) {
            RemittanceWriter::markPending($run);
            $this->release($e->retryAfter);

            return;
        } catch (Throwable $e) {
            Log::error('Claude routine fire failed', ['uuid' => $uuid, 'message' => $e->getMessage()]);
            RemittanceWriter::fail($run, 'We couldn\'t start processing this document. Please try again.');

            return;
        }

        $run->update(['session_url' => $fired['session_url']]);

        Log::info('Claude routine fired', ['uuid' => $uuid, 'session_url' => $fired['session_url']]);

        ExpireClaudeExtraction::dispatch($run->id)->delay(now()->addMinutes(10));
    }

    public function failed(?Throwable $exception): void
    {
        $run = ExtractionRun::find($this->runId);

        if ($run !== null && in_array($run->status, [ExtractionStatus::Pending, ExtractionStatus::Processing], true)) {
            RemittanceWriter::fail($run, 'We couldn\'t start processing this document. Please try again.');
        }
    }
}
