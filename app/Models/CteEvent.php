<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CteEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'cte_document_id', 'event_type', 'sequence', 'attempt', 'status', 'event_id', 'protocol',
        'event_date', 'payload_xml', 'response_xml', 'xml_path', 'sha256', 'error_code', 'error_message',
        'transport_http_status', 'response_content_type', 'response_body',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'attempt' => 'integer',
            'event_date' => 'date',
            'transport_http_status' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CteDocument::class, 'cte_document_id');
    }
}
