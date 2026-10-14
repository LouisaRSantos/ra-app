<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class ExtractionStore
{
    private const TTL_SECONDS = 3600;

    public static function create(string $uuid, array $data): void
    {
        Cache::put(self::key($uuid), array_merge([
            'user_id' => null,
            'status' => 'pending',
            'error_message' => null,
            'disk_paths' => [],
            'mime_types' => [],
            'original_filenames' => [],
            'payment_reference_number' => null,
            'cr_number' => null,
            'total_amount' => null,
            'total_cwt_amount' => null,
            'deposit_date' => null,
            'low_confidence_fields' => [],
            'invoices' => [],
            'confirmed' => false,
        ], $data), self::TTL_SECONDS);
    }

    public static function get(string $uuid): ?array
    {
        return Cache::get(self::key($uuid));
    }

    /**
     * The entry only if the signed-in user may see it (owner, or holds remittances.view-all).
     * Anything else is a 404, so ids cannot be probed.
     */
    public static function getForUser(string $uuid): array
    {
        $entry = self::get($uuid);
        $user = auth()->user();

        abort_if($entry === null || $user === null
            || ($entry['user_id'] !== $user->id && ! $user->can('remittances.view-all')), 404);

        return $entry;
    }

    public static function update(string $uuid, array $data): void
    {
        $entry = self::get($uuid);

        if ($entry === null) {
            return;
        }

        Cache::put(self::key($uuid), array_merge($entry, $data), self::TTL_SECONDS);
    }

    private static function key(string $uuid): string
    {
        return "extraction:{$uuid}";
    }
}
