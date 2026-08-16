<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentBatchItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_batch_id', 'register_id', 'vehicle_plate', 'amount',
        'cte_number', 'delivery_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'delivery_confirmed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }
}
