<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cadastro fiscal neutro de empresas das operações — pátios, seguradoras e
 * distribuidoras — consumido por CIOT (pagante/destinatário) e CT-e (rem/dest).
 * A tabela não guarda papel: quem é pagante, remetente ou destinatário é
 * decisão do fluxo, não do cadastro.
 */
class Company extends Model
{
    /** @use HasFactory<\Database\Factories\CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'cnpj', 'ie', 'street', 'number', 'complement', 'district',
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
