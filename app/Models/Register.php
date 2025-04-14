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
        'status' => RegisterStatusEnum::class,
    ];
}
