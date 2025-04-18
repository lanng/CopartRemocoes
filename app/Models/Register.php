<?php

namespace App\Models;

use App\Enums\RegisterStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Register extends Model
{
    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function ($register) {
            if ($register->pdf_path) {
                Storage::disk('s3')->delete($register->pdf_path);
            }
        });
    }

    protected $fillable = [
        'vehicle_model',
        'vehicle_plate',
        'origin_city',
        'destination_city',
        'deadline_withdraw',
        'deadline_delivery',
        'collected_date',
        'driver',
        'driver_plate',
        'vehicle_id',
        'value',
        'status',
        'pdf_path',
        'notes',
    ];

    protected $casts = [
        'deadline_withdraw' => 'datetime', // Cast to Carbon object
        'deadline_delivery' => 'datetime', // Cast to Carbon object
        'collected_date'    => 'datetime', // Cast to Carbon object
        'status'            => RegisterStatusEnum::class, // Cast status to your Enum
        'value'             => 'decimal:2', // Example: Cast value if it's a decimal/money type
    ];

    // Implement the helper methods suggested before
    public function isCollected(): bool
    {
        // Ensure status is cast to enum before comparison
        return in_array($this->status, [
            RegisterStatusEnum::COLLECTED,
            RegisterStatusEnum::DELIVERED // Or whatever statuses mean "collected"
        ]);
    }

    public function isDelivered(): bool
    {
        // Ensure status is cast to enum before comparison
        return $this->status === RegisterStatusEnum::DELIVERED;
    }

    public function isCancelled(): bool
    {
        return $this->status === RegisterStatusEnum::CANCELLED;
    }
}
