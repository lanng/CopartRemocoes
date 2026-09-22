<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CityDistance extends Model
{
    /** @use HasFactory<\Database\Factories\CityDistanceFactory> */
    use HasFactory;

    protected $fillable = [
        'origin_ibge', 'destination_ibge', 'km', 'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'km' => 'float',
            'fetched_at' => 'datetime',
        ];
    }
}
