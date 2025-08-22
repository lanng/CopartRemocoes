<?php
namespace App\Models;

use App\Enums\RegisterStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Register extends Model
{
    use LogsActivity;

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
        'insurance',
        'fipe_value',
        'payment_code',
    ];

    protected $casts = [
        'deadline_withdraw' => 'datetime',
        'deadline_delivery' => 'datetime',
        'collected_date'    => 'datetime',
        'status'            => RegisterStatusEnum::class,
        'value'             => 'decimal:2',
    ];

    // Implement the helper methods suggested before
    public function isCollected(): bool
    {
        return in_array($this->status, [
            RegisterStatusEnum::COLLECTED,
            RegisterStatusEnum::PAID,
        ]);
    }

    public function isPaid(): bool
    {
        return $this->status === RegisterStatusEnum::PAID;
    }

    public function isCancelled(): bool
    {
        return $this->status === RegisterStatusEnum::CANCELLED;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['vehicle_model', 'vehicle_plate', 'origin_city', 'notes', 'status', 'driver', 'collected_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "O registro foi {$eventName}")
            ->useLogName('RegisterLog');
    }
}
