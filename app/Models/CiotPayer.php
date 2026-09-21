<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CiotPayer extends Model
{
    /** @use HasFactory<\Database\Factories\CiotPayerFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'cnpj', 'street', 'number', 'complement', 'district',
        'city', 'state', 'zipcode', 'ibge_code', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function ciots(): HasMany
    {
        return $this->hasMany(Ciot::class, 'payer_id');
    }

    /**
     * Localização completa no formato usado nos payloads do CIOT.
     *
     * @return array{cidade: string, uf: string, cep: ?string, ibge: ?string}
     */
    public function location(): array
    {
        return [
            'cidade' => $this->city,
            'uf' => $this->state,
            'cep' => $this->zipcode,
            'ibge' => $this->ibge_code,
        ];
    }
}
