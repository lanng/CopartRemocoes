<?php

namespace App\Models;

use App\Enums\CiotLineEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MdfeDriver extends Model
{
    /** @use HasFactory<\Database\Factories\MdfeDriverFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'cpf',
        'line',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MdfeDriver $driver): void {
            if (! $driver->is_active || $driver->line !== CiotLineEnum::VehicleRemoval->value) {
                $driver->is_default = false;
            }
        });

        static::saved(function (MdfeDriver $driver): void {
            if ($driver->is_default) {
                static::query()
                    ->where('line', CiotLineEnum::VehicleRemoval->value)
                    ->where('is_default', true)
                    ->whereKeyNot($driver->getKey())
                    ->update(['is_default' => false]);
            }
        });
    }
}
