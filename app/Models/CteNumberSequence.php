<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sequência de numeração do emissor direto (spec §3): uma linha por
 * CNPJ/série, incrementada atomicamente com lockForUpdate. A seed de produção
 * é manual, no switch do Lab (último nCT + 1) — nunca inferida.
 */
class CteNumberSequence extends Model
{
    protected $fillable = [
        'cnpj',
        'serie',
        'next_number',
    ];
}
