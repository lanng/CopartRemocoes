<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    /** @use HasFactory<\Database\Factories\CityFactory> */
    use HasFactory;

    protected $fillable = [
        'ibge_code', 'name', 'state', 'latitude', 'longitude',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Par ordenado [longitude, latitude] no formato do OpenRouteService.
     *
     * @return array{float, float}|null
     */
    public function coordinates(): ?array
    {
        if (! $this->hasCoordinates()) {
            return null;
        }

        return [$this->longitude, $this->latitude];
    }
}
