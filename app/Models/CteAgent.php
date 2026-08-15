<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class CteAgent extends Model
{
    public const string SINGLETON_KEY = 'host';

    /** @use HasFactory<\Database\Factories\CteAgentFactory> */
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'name',
        'hostname',
        'version',
        'capabilities',
        'is_dry_run',
        'is_active',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'is_dry_run' => 'boolean',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }
}
