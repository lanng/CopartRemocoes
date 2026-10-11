<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sequência de numeração do emissor direto (spec §3): uma linha por
 * CNPJ/série/ambiente, incrementada atomicamente com lockForUpdate. A seed de produção
 * é manual, no switch do Lab (último nCT + 1) — nunca inferida.
 */
class CteNumberSequence extends Model
{
    protected $attributes = ['tp_amb' => 2];

    protected $fillable = [
        'cnpj',
        'serie',
        'tp_amb',
        'next_number',
    ];

    protected function casts(): array
    {
        return ['tp_amb' => 'integer', 'serie' => 'integer', 'next_number' => 'integer'];
    }
}
