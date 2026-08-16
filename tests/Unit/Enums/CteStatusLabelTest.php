<?php

namespace Tests\Unit\Enums;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use PHPUnit\Framework\TestCase;

class CteStatusLabelTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('batchStatuses')]
    public function test_each_batch_status_has_expected_persisted_value_label_and_filament_color(CteEmissionBatchStatusEnum $enum, string $expectedValue, string $expectedLabel, string $expectedColor): void
    {
        $this->assertSame($expectedValue, $enum->value);
        $this->assertSame($expectedLabel, $enum->label());
        $this->assertSame($expectedColor, $enum->color());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('documentStatuses')]
    public function test_each_document_status_has_expected_persisted_value_label_and_filament_color(CteDocumentStatusEnum $enum, string $expectedValue, string $expectedLabel, string $expectedColor): void
    {
        $this->assertSame($expectedValue, $enum->value);
        $this->assertSame($expectedLabel, $enum->label());
        $this->assertSame($expectedColor, $enum->color());
    }

    /**
     * @return array<string, array{0: CteEmissionBatchStatusEnum, 1: string, 2: string, 3: string}>
     */
    public static function batchStatuses(): array
    {
        return [
            'draft' => [CteEmissionBatchStatusEnum::DRAFT, 'draft', 'Rascunho', 'gray'],
            'approved' => [CteEmissionBatchStatusEnum::APPROVED, 'approved', 'Aprovado', 'info'],
            'processing' => [CteEmissionBatchStatusEnum::PROCESSING, 'processing', 'Em processamento', 'warning'],
            'completed' => [CteEmissionBatchStatusEnum::COMPLETED, 'completed', 'Concluído', 'success'],
            'completed with errors' => [CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS, 'completed_with_errors', 'Concluído com erros', 'danger'],
            'cancelled' => [CteEmissionBatchStatusEnum::CANCELLED, 'cancelled', 'Cancelado', 'danger'],
        ];
    }

    /**
     * @return array<string, array{0: CteDocumentStatusEnum, 1: string, 2: string, 3: string}>
     */
    public static function documentStatuses(): array
    {
        return [
            'draft' => [CteDocumentStatusEnum::DRAFT, 'draft', 'Rascunho', 'gray'],
            'queued' => [CteDocumentStatusEnum::QUEUED, 'queued', 'Na fila', 'info'],
            'claimed' => [CteDocumentStatusEnum::CLAIMED, 'claimed', 'Capturado', 'info'],
            'filling' => [CteDocumentStatusEnum::FILLING, 'filling', 'Preenchendo', 'warning'],
            'validating' => [CteDocumentStatusEnum::VALIDATING, 'validating', 'Validando', 'warning'],
            'ready to authorize' => [CteDocumentStatusEnum::READY_TO_AUTHORIZE, 'ready_to_authorize', 'Pronto para autorizar', 'primary'],
            'authorizing' => [CteDocumentStatusEnum::AUTHORIZING, 'authorizing', 'Autorizando', 'primary'],
            'waiting for XML' => [CteDocumentStatusEnum::WAITING_FOR_XML, 'waiting_for_xml', 'Aguardando XML', 'warning'],
            'dry run completed' => [CteDocumentStatusEnum::DRY_RUN_COMPLETED, 'dry_run_completed', 'Simulação concluída', 'success'],
            'authorized' => [CteDocumentStatusEnum::AUTHORIZED, 'authorized', 'Autorizado', 'success'],
            'rejected' => [CteDocumentStatusEnum::REJECTED, 'rejected', 'Rejeitado', 'danger'],
            'failed before authorization' => [CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION, 'failed_before_authorization', 'Falha antes da autorização', 'danger'],
            'reconciliation required' => [CteDocumentStatusEnum::RECONCILIATION_REQUIRED, 'reconciliation_required', 'Reconciliação necessária', 'warning'],
            'cancelled' => [CteDocumentStatusEnum::CANCELLED, 'cancelled', 'Cancelado', 'danger'],
            'superseded' => [CteDocumentStatusEnum::SUPERSEDED, 'superseded', 'Substituído', 'gray'],
        ];
    }
}
