<?php

namespace App\Claude;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The only place that knows about the (experimental) Claude routines fire API.
 * If the API changes, or we switch to the Messages API, only this class changes.
 */
class RoutineClient
{
    /**
     * Starts one routine run. Never retried here: the fire endpoint has no idempotency key,
     * so every call is a new run.
     *
     * @return array{session_url: ?string}
     *
     * @throws RoutineQuotaExceeded on 429
     */
    public function fire(array $payload): array
    {
        $config = config('services.claude_routine');

        $response = Http::timeout(30)
            ->withToken($config['token'])
            ->withHeaders([
                'anthropic-beta' => $config['beta'],
                'anthropic-version' => '2023-06-01',
            ])
            ->post("{$config['endpoint']}/{$config['id']}/fire", ['text' => json_encode($payload)]);

        if ($response->status() === 429) {
            throw new RoutineQuotaExceeded((int) $response->header('Retry-After') ?: 300);
        }

        if ($response->failed()) {
            throw new RuntimeException('Routine fire failed with status '.$response->status());
        }

        return ['session_url' => $response->json('claude_code_session_url') ?? $response->json('session_url')];
    }
}
