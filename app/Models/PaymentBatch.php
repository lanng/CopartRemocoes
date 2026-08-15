<?php

namespace App\Models;

use App\Enums\PaymentBatchStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'status', 'window_start', 'window_end', 'generated_at', 'total_amount',
        'outlook_sync_failed', 'outlook_sync_error', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentBatchStatusEnum::class,
            'window_start' => 'date',
            'window_end' => 'date',
            'generated_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'outlook_sync_failed' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentBatchItem::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
