<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DfeDocumentReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_type', 'resource_id', 'mdfe_document_id',
    ];

    public function mdfeDocument(): BelongsTo
    {
        return $this->belongsTo(MdfeDocument::class);
    }
}
