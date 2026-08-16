<?php

namespace App\Models;

use App\Enums\CteEmissionBatchStatusEnum;
use Illuminate\Database\Eloquent\Collection;
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

    public function totalCargoValueInCents(): int
    {
        return $this->totalSnapshotValueInCents('fipe_value');
    }

    public function totalTransportValueInCents(): int
    {
        return $this->totalSnapshotValueInCents('value');
    }

    private function totalSnapshotValueInCents(string $field): int
    {
        /** @var Collection<int, CteDocument> $documents */
        $documents = $this->documents;

        return $documents->sum(
            fn (CteDocument $document): int => self::decimalValueInCents($document->snapshot[$field] ?? null)
        );
    }

    private static function decimalValueInCents(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $value = (string) $value;
        $isNegative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        $cents = ((int) $whole * 100) + (int) $fraction;

        return $isNegative ? -$cents : $cents;
    }
}
