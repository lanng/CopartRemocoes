<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CteDocumentNfe extends Model
{
    use HasFactory;

    protected $table = 'cte_document_nfes';

    protected $fillable = [
        'cte_document_id', 'access_key', 'number', 'series', 'source_filename', 'xml_path',
        'sha256', 'extracted_data', 'operator_complements', 'operator_complements_user_id',
    ];

    protected function casts(): array
    {
        return [
            'extracted_data' => 'array',
            'operator_complements' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CteDocument::class, 'cte_document_id');
    }

    public function operatorComplementsUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_complements_user_id');
    }
}
