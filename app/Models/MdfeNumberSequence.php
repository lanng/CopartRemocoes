<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MdfeNumberSequence extends Model
{
    /** @use HasFactory<\Database\Factories\MdfeNumberSequenceFactory> */
    use HasFactory;

    protected $fillable = ['cnpj', 'series', 'next_number'];

    protected function casts(): array
    {
        return [
            'series' => 'integer',
            'next_number' => 'integer',
        ];
    }
}
