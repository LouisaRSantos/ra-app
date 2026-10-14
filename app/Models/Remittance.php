<?php

namespace App\Models;

use App\Enums\ExtractionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Remittance extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id', 'status', 'payment_reference_number', 'cr_number', 'deposit_date',
        'total_amount', 'total_cwt_amount', 'low_confidence_fields',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExtractionStatus::class,
            'deposit_date' => 'date',
            // decimal:2 keeps money as an exact string, never a float.
            'total_amount' => 'decimal:2',
            'total_cwt_amount' => 'decimal:2',
            'low_confidence_fields' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    /** The bigint id stays internal; URLs and routes use the uuid. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(RemittanceFile::class)->orderBy('position');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RemittanceInvoice::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ExtractionRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(ExtractionRun::class)->latestOfMany();
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * The one place that decides which transactions a user may see:
     * everything with `remittances.view-all`, otherwise only their own.
     * Controllers and lists must go through this scope, never filter ad hoc.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('remittances.view-all') ? $query : $query->where($this->qualifyColumn('user_id'), $user->id);
    }
}
