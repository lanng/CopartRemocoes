<?php

namespace App\Models;

use App\Enums\CompanyEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\RegisterStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Register extends Model
{
    use HasFactory, LogsActivity;

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function ($register) {
            $paths = array_values(array_filter([
                $register->pdf_path,
                $register->consignor_letter_path,
            ]));

            if ($paths !== []) {
                Storage::disk('s3')->delete($paths);
            }
        });
    }

    protected $fillable = [
        'company',
        'vehicle_model',
        'vehicle_plate',
        'origin_city',
        'destination_city',
        'deadline_withdraw',
        'deadline_delivery',
        'collected_date',
        'delivery_confirmed_at',
        'payment_deferred_at',
        'driver',
        'driver_plate',
        'vehicle_id',
        'value',
        'status',
        'pdf_path',
        'pdf_sha256',
        'consignor_letter_path',
        'consignor_letter_sha256',
        'notes',
        'insurance',
        'fipe_value',
        'payment_code',
        'tow_yard',
    ];

    protected $casts = [
        'deadline_withdraw' => 'datetime',
        'deadline_delivery' => 'datetime',
        'collected_date' => 'datetime',
        'delivery_confirmed_at' => 'datetime',
        'payment_deferred_at' => 'datetime',
        'status' => RegisterStatusEnum::class,
        'value' => 'decimal:2',
        'fipe_value' => 'decimal:2',
        'company' => CompanyEnum::class,
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

    public function isDelivered(): bool
    {
        return $this->status === RegisterStatusEnum::DELIVERED;
    }

    public function cteDocuments(): HasMany
    {
        return $this->hasMany(CteDocument::class);
    }

    public function latestAuthorizedCteDocument(): HasOne
    {
        return $this->hasOne(CteDocument::class)
            ->ofMany([
                'authorized_at' => 'max',
                'id' => 'max',
            ], function ($query): void {
                $query
                    ->where('status', CteDocumentStatusEnum::AUTHORIZED)
                    ->whereNotNull('cte_number');
            });
    }

    public function paymentBatchItems(): HasMany
    {
        return $this->hasMany(PaymentBatchItem::class);
    }

    public function integrationInboxItems(): HasMany
    {
        return $this->hasMany(IntegrationInboxItem::class);
    }

    public function unresolvedRemovalImports(): HasMany
    {
        return $this->integrationInboxItems()
            ->where('message_type', 'removal_request')
            ->whereIn('status', ['pending', 'alert'])
            ->whereNull('resolved_at');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'vehicle_model',
                'vehicle_plate',
                'origin_city',
                'destination_city',
                'deadline_withdraw',
                'deadline_delivery',
                'vehicle_id',
                'value',
                'notes',
                'status',
                'driver',
                'collected_date',
                'insurance',
                'fipe_value',
                'payment_code',
                'pdf_path',
                'pdf_sha256',
                'consignor_letter_path',
                'consignor_letter_sha256',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "O registro foi {$eventName}")
            ->useLogName('RegisterLog');
    }
}
