<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemittanceFile extends Model
{
    use HasFactory;

    protected $fillable = ['remittance_id', 'position', 'path', 'original_name', 'mime_type', 'size', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    public function remittance(): BelongsTo
    {
        return $this->belongsTo(Remittance::class);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_public'), true);
    }

    /**
     * Files a user may open: public files when they hold `files.view-public`, plus every file of
     * the remittances they can see (all with `remittances.view-all`, otherwise their own).
     * With none of those permissions the scope matches nothing.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->whereRaw('1 = 0');

            if ($user->can('files.view-public')) {
                $q->orWhere($this->qualifyColumn('is_public'), true);
            }

            if ($user->can('remittances.view-all')) {
                $q->orWhereRaw('1 = 1');
            } elseif ($user->can('remittances.view-own')) {
                $q->orWhereIn($this->qualifyColumn('remittance_id'), Remittance::query()->select('id')->where('user_id', $user->id));
            }
        });
    }
}
