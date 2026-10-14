<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment applied to one sales invoice within a remittance. */
class RemittanceInvoice extends Model
{
    use HasFactory;

    protected $fillable = ['remittance_id', 'sales_invoice_id', 'amount', 'cwt_amount', 'low_confidence'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cwt_amount' => 'decimal:2',
            'low_confidence' => 'boolean',
        ];
    }

    public function remittance(): BelongsTo
    {
        return $this->belongsTo(Remittance::class);
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }
}
