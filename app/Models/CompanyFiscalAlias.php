<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CompanyFiscalAlias extends Model
{
    /** @use HasFactory<\Database\Factories\CompanyFiscalAliasFactory> */
    use HasFactory;

    protected $fillable = ['role', 'source_name', 'company_id', 'city_id', 'created_by'];

    public static function normalize(string $value): string
    {
        $value = Str::upper(Str::ascii(trim($value)));
        $value = preg_replace('/\bS[\s.\/]*A\b/', 'SA', $value) ?? $value;

        return trim(preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value);
    }

    protected function sourceName(): Attribute
    {
        return Attribute::make(set: fn (string $value): array => [
            'source_name' => trim($value),
            'normalized_name' => self::normalize($value),
        ]);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
