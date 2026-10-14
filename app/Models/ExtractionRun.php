<?php

namespace App\Models;

use App\Enums\ExtractionEngine;
use App\Enums\ExtractionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtractionRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'remittance_id', 'engine', 'status', 'callback_token_hash', 'session_url',
        'fired_at', 'completed_at', 'duration_seconds', 'error', 'raw_result',
    ];

    // The token hash is only ever compared, never serialised out.
    protected $hidden = ['callback_token_hash'];

    protected function casts(): array
    {
        return [
            'engine' => ExtractionEngine::class,
            'status' => ExtractionStatus::class,
            'fired_at' => 'datetime',
            'completed_at' => 'datetime',
            'raw_result' => 'array',
        ];
    }

    public function remittance(): BelongsTo
    {
        return $this->belongsTo(Remittance::class);
    }

    /** Store only a hash of the one-time token. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function tokenMatches(?string $token): bool
    {
        return $this->callback_token_hash !== null && $token !== null
            && hash_equals($this->callback_token_hash, self::hashToken($token));
    }
}
