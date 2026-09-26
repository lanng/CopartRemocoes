<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Models\MdfeDocument;
use App\Services\Cte\ReconcileMdfeDocument;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ReconcileMdfeDocumentTest extends TestCase
{
    use RefreshDatabase;

    /** 35 2609 12563112000130 58 001 000000123 1 12345678 5 */
    private const ACCESS_KEY = '35260912563112000130580010000001231123456785';

    public function test_reconciles_the_document_deriving_number_and_series_from_the_key(): void
    {
        $mdfe = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ]);

        $result = app(ReconcileMdfeDocument::class)->handle($mdfe, self::ACCESS_KEY, '135260000123456');

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $result->status);
        $this->assertSame(self::ACCESS_KEY, $result->access_key);
        $this->assertSame('000000123', $result->mdfe_number);
        $this->assertSame('001', $result->series);
        $this->assertSame('135260000123456', $result->protocol);
        $this->assertSame('100', $result->fiscal_status_code);
        $this->assertNotNull($result->authorized_at);
    }

    public function test_accepts_a_key_with_punctuation(): void
    {
        $mdfe = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ]);

        $result = app(ReconcileMdfeDocument::class)->handle(
            $mdfe,
            '3526 0912 5631 1200 0130 5800 1000 0001 2311 2345 6785',
        );

        $this->assertSame('000000123', $result->mdfe_number);
    }

    #[TestWith(['35260912563112000130580010000001231'])]
    #[TestWith(['ABC'])]
    public function test_rejects_a_key_that_is_not_44_digits(string $key): void
    {
        $mdfe = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('44 dígitos');

        app(ReconcileMdfeDocument::class)->handle($mdfe, $key);
    }

    public function test_rejects_documents_outside_reconciliation(): void
    {
        $mdfe = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::WAITING_FOR_XML,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('reconciliação');

        app(ReconcileMdfeDocument::class)->handle($mdfe, self::ACCESS_KEY);
    }
}
