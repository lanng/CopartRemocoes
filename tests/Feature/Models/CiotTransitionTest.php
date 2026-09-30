<?php

namespace Tests\Feature\Models;

use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CiotTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_sources_maps_the_full_state_machine(): void
    {
        $this->assertSame([CiotStatusEnum::DRAFT, CiotStatusEnum::FAILED], CiotStatusEnum::PENDING->allowedSources());
        $this->assertSame([CiotStatusEnum::PENDING], CiotStatusEnum::ISSUED->allowedSources());
        $this->assertSame([CiotStatusEnum::ISSUED], CiotStatusEnum::CANCELED->allowedSources());
        $this->assertSame([CiotStatusEnum::ISSUED], CiotStatusEnum::CLOSED->allowedSources());
        $this->assertSame([CiotStatusEnum::PENDING, CiotStatusEnum::DRAFT], CiotStatusEnum::FAILED->allowedSources());
        $this->assertSame([], CiotStatusEnum::DRAFT->allowedSources());
    }

    public function test_transition_to_persists_the_fields_and_the_status(): void
    {
        $ciot = Ciot::factory()->create();

        $result = $ciot->transitionTo(
            CiotStatusEnum::PENDING,
            [
                'id_operacao_transporte' => '560000569999',
                'payload' => ['IdOperacaoTransporte' => '560000569999'],
                'error_code' => null,
                'error_message' => null,
                'response' => null,
            ],
            guardMessage: 'Somente CIOTs em rascunho ou com falha podem ser emitidos.',
        );

        $this->assertSame(CiotStatusEnum::PENDING, $result->status);
        $this->assertSame('560000569999', $result->id_operacao_transporte);
        $this->assertSame('560000569999', $result->payload['IdOperacaoTransporte']);

        $stored = $ciot->refresh();

        $this->assertSame(CiotStatusEnum::PENDING, $stored->status);
        $this->assertSame('560000569999', $stored->id_operacao_transporte);
        $this->assertSame('560000569999', $stored->payload['IdOperacaoTransporte']);
    }

    public function test_transition_to_violation_throws_the_legacy_guard_message(): void
    {
        $ciot = Ciot::factory()->issued()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Somente CIOTs em rascunho ou com falha podem ser emitidos.');

        $ciot->transitionTo(
            CiotStatusEnum::PENDING,
            guardMessage: 'Somente CIOTs em rascunho ou com falha podem ser emitidos.',
        );
    }

    public function test_transition_from_returns_null_and_does_not_mutate_when_the_locked_status_diverges(): void
    {
        $ciot = Ciot::factory()->create(['status' => CiotStatusEnum::PENDING]);

        // A linha mudou de status por outro caminho depois de a instância
        // chamadora ter sido carregada.
        Ciot::query()->whereKey($ciot->id)->update(['status' => CiotStatusEnum::ISSUED->value]);

        $result = $ciot->transitionFrom(CiotStatusEnum::PENDING, CiotStatusEnum::ISSUED, fn (Ciot $locked): array => [
            'ciot_number' => '520031583158',
            'verifier_code' => '1234',
        ]);

        $this->assertNull($result);
        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->refresh()->status);
        $this->assertNull($ciot->refresh()->ciot_number);
        $this->assertNull($ciot->refresh()->verifier_code);
    }

    public function test_the_transition_from_callable_reads_the_locked_instance_data(): void
    {
        $created = Ciot::factory()->issued()->create();

        $caller = Ciot::query()->find($created->id);

        // O response chega ao banco DEPOIS de a instância chamadora ter sido
        // carregada — o merge precisa ler o dado fresco da instância travada.
        Ciot::query()->whereKey($created->id)->update([
            'response' => json_encode(['codigo' => '110'], JSON_UNESCAPED_UNICODE),
        ]);

        $result = $caller->transitionFrom(CiotStatusEnum::ISSUED, CiotStatusEnum::CANCELED, fn (Ciot $locked): array => [
            'cancel_reason' => 'Carga cancelada pelo cliente',
            'response' => array_merge($locked->response ?? [], ['cancelamento' => ['Codigo' => '110']]),
        ]);

        $this->assertSame(CiotStatusEnum::CANCELED, $result->status);
        $this->assertSame('Carga cancelada pelo cliente', $result->cancel_reason);
        $this->assertSame('110', $result->response['codigo']);
        $this->assertSame('110', $result->response['cancelamento']['Codigo']);
    }

    public function test_transitions_keep_firing_the_activity_log(): void
    {
        $ciot = Ciot::factory()->create();

        $ciot->transitionTo(CiotStatusEnum::PENDING);

        $activity = Activity::query()
            ->where('subject_type', Ciot::class)
            ->where('subject_id', $ciot->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('updated', $activity->description);
        $this->assertSame('pending', $activity->properties->toArray()['attributes']['status']);
    }
}
