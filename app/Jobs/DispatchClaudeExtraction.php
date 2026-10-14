<?php

namespace App\Jobs;

use App\Claude\RoutineClient;
use App\Claude\RoutineQuotaExceeded;
use App\Support\ExtractionStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fires the Claude routine once. The result arrives later via ClaudeExtractionController::callback.
 */
class DispatchClaudeExtraction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Extra attempts exist only for quota (429) releases; every other failure is final (no auto re-fire).
    public int $tries = 10;

    public function __construct(private readonly string $uuid)
    {
    }

    public function handle(RoutineClient $client): void
    {
        $entry = ExtractionStore::get($this->uuid);

        if ($entry === null || $entry['status'] !== 'pending') {
            return;
        }

        $token = Str::random(64);

        $imageUrls = [];
        foreach (array_keys($entry['disk_paths']) as $index) {
            $imageUrls[] = URL::temporarySignedRoute('claude.image', now()->addMinutes(30), ['uuid' => $this->uuid, 'index' => $index]);
        }

        try {
            $run = $client->fire([
                'document_id' => $this->uuid,
                'image_urls' => $imageUrls,
                'callback_url' => route('claude.callback', ['uuid' => $this->uuid]),
                'callback_token' => $token,
            ]);
        } catch (RoutineQuotaExceeded $e) {
            $this->release($e->retryAfter);

            return;
        } catch (Throwable $e) {
            Log::error('Claude routine fire failed', ['uuid' => $this->uuid, 'message' => $e->getMessage()]);
            $this->markFailed();

            return;
        }

        ExtractionStore::update($this->uuid, [
            'status' => 'processing',
            'callback_token' => $token,
            'routine_session_url' => $run['session_url'],
            'fired_at' => now()->timestamp,
        ]);

        Log::info('Claude routine fired', ['uuid' => $this->uuid, 'session_url' => $run['session_url']]);

        ExpireClaudeExtraction::dispatch($this->uuid)->delay(now()->addMinutes(10));
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed();
    }

    private function markFailed(): void
    {
        ExtractionStore::update($this->uuid, [
            'status' => 'failed',
            'error_message' => 'We couldn\'t start processing this document. Please try again.',
        ]);
    }
}
