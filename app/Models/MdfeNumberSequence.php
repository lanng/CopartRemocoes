<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MdfeNumberSequence extends Model
{
    /** @use HasFactory<\Database\Factories\MdfeNumberSequenceFactory> */
    use HasFactory;

    protected $attributes = ['tp_amb' => 2];

    protected $fillable = ['cnpj', 'series', 'tp_amb', 'next_number'];

    protected function casts(): array
    {
        return [
            'tp_amb' => 'integer',
            'series' => 'integer',
            'next_number' => 'integer',
        ];
    }
}
