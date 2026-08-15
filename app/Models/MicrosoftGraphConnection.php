<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MicrosoftGraphConnection extends Model
{
    /** @use HasFactory<\Database\Factories\MicrosoftGraphConnectionFactory> */
    use HasFactory;

    protected $fillable = [
        'account_email', 'access_token', 'refresh_token', 'expires_at', 'delta_link',
        'activated_at', 'last_synced_at', 'last_error', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'delta_link' => 'encrypted',
            'expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
