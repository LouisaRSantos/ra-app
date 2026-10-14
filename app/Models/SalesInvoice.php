<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoice extends Model
{
    use HasFactory;

    protected $fillable = ['si_number'];

    public function lines(): HasMany
    {
        return $this->hasMany(RemittanceInvoice::class);
    }
}
