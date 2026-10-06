<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdfeEvent extends Model
{
    /** @var list<string> */
    public const CORRECTABLE_REJECTION_CODES = ['614', '714', '222'];

    /** @use HasFactory<\Database\Factories\MdfeEventFactory> */
    use HasFactory;

    protected $fillable = [
        'mdfe_document_id', 'event_type', 'sequence', 'attempt', 'status', 'event_id', 'protocol',
        'event_date', 'c_uf', 'c_mun', 'payload_xml', 'response_xml', 'xml_path', 'sha256',
        'error_code', 'error_message', 'transport_http_status', 'response_content_type', 'response_body',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'sequence' => 'integer',
            'attempt' => 'integer',
            'transport_http_status' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(MdfeDocument::class, 'mdfe_document_id');
    }

    public function isRetryableRejection(): bool
    {
        return $this->status === 'rejected_retryable'
            && in_array((string) $this->error_code, self::CORRECTABLE_REJECTION_CODES, true);
    }
}
