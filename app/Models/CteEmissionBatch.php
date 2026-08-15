<?php

namespace App\Models;

use App\Enums\CteEmissionBatchStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CteEmissionBatch extends Model
{
    /** @use HasFactory<\Database\Factories\CteEmissionBatchFactory> */
    use HasFactory;

    protected $fillable = [
        'status',
        'execution_mode',
        'created_by',
        'approved_by',
        'approved_at',
        'processing_started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CteEmissionBatchStatusEnum::class,
            'approved_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CteDocument::class);
    }
}
