<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentBatchRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'window_start', 'window_end', 'processed_at', 'result', 'item_count',
        'outlook_sync_failed', 'outlook_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'date',
            'window_end' => 'date',
            'processed_at' => 'datetime',
            'item_count' => 'integer',
            'outlook_sync_failed' => 'boolean',
        ];
    }
}
