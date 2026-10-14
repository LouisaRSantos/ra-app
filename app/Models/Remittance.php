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
        // set only by RemittanceWriter::confirm(), never from request input
        'confirmed_by', 'confirmed_at',
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

    /** Flat array the review screen renders from (kept stable so the Blade view stays simple). */
    public function reviewData(): array
    {
        $this->loadMissing(['files', 'lines.salesInvoice', 'latestRun']);

        return [
            'status' => $this->status->value,
            'error_message' => $this->status === ExtractionStatus::Failed
                ? ($this->latestRun?->error ?: 'We couldn\'t process this document. Please try re-uploading a clearer image.')
                : null,
            'disk_paths' => $this->files->pluck('path')->all(),
            'original_filenames' => $this->files->pluck('original_name')->all(),
            'payment_reference_number' => $this->payment_reference_number,
            'cr_number' => $this->cr_number,
            'total_amount' => $this->total_amount,
            'total_cwt_amount' => $this->total_cwt_amount,
            'deposit_date' => $this->deposit_date?->toDateString(),
            'low_confidence_fields' => $this->low_confidence_fields ?? [],
            'invoices' => $this->lines->map(fn (RemittanceInvoice $l) => [
                'si_number' => $l->salesInvoice->si_number,
                'amount' => $l->amount,
                'cwt_amount' => $l->cwt_amount,
                'low_confidence_fields' => $l->low_confidence_fields ?? [],
            ])->all(),
            'confirmed' => $this->isConfirmed(),
        ];
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
