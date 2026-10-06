<?php

namespace App\Filament\Resources\CteEmissionBatchResource\Pages;

use App\Enums\CiotLineEnum;
use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Filament\Actions\GenerateCiotForBatchAction;
use App\Filament\Resources\CteEmissionBatchResource;
use App\Filament\Support\CiotEmissionNotice;
use App\Jobs\EmitMdfeDirectJob;
use App\Models\Ciot;
use App\Models\City;
use App\Models\MdfeDocument;
use App\Models\MdfeDriver;
use App\Services\Ciot\DispatchMdfeForBatch;
use App\Services\Ciot\EmitCiotDeclaration;
use App\Services\Ciot\QueueDirectMdfeEmission;
use App\Services\Cte\ApproveCteEmissionBatch;
use App\Services\Cte\DeleteDraftCteEmissionBatch;
use App\Services\Cte\ReconcileMdfeDocument;
use App\Services\Dfe\CloseMdfeDocument;
use App\Services\Dfe\DfeDocumentStore;
use App\Services\Dfe\GenerateDamdfePdf;
use DomainException;
use Filament\Actions;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class ViewCteEmissionBatch extends ViewRecord
{
    protected static string $resource = CteEmissionBatchResource::class;

    public function refreshBatch(): void
    {
        $this->authorizeAccess();
        $this->getRecord()->refresh();
    }

    protected function issuedCiot(): ?Ciot
    {
        return $this->record->ciots()
            ->where('status', CiotStatusEnum::ISSUED->value)
            ->latest('id')
            ->first();
    }

    protected function ciotForDirectMdfe(): ?Ciot
    {
        $issuedCiot = $this->issuedCiot();

        if ($issuedCiot !== null || (string) config('dfe.tp_amb') !== '2') {
            return $issuedCiot;
        }

        return $this->record->ciots()
            ->where('status', CiotStatusEnum::FAILED->value)
            ->where('error_code', '217')
            ->whereNotNull('payload')
            ->latest('id')
            ->first();
    }

    protected function mdfeAvailableForClosure(): ?MdfeDocument
    {
        return $this->record->mdfeDocuments()
            ->where('status', CteDocumentStatusEnum::AUTHORIZED->value)
            ->where('emitter', 'direct')
            ->whereNotNull('access_key')
            ->whereNotNull('protocol')
            ->latest('id')
            ->get()
            ->first(fn (MdfeDocument $document): bool => $document->isClosureAvailable());
    }

    /**
     * MDF-es reiniciáveis: falhos/rejeitados OU presos em estado intermediário
     * com lease expirado (agent morreu/pausou no meio do fluxo sem reportar
     * falha terminal — o documento nunca sairia daí pelos próprios meios).
     */
    protected function restartableMdfeDocuments(): HasMany
    {
        return $this->record->mdfeDocuments()
            ->where(function ($query): void {
                $query->whereIn('status', [
                    CteDocumentStatusEnum::REJECTED->value,
                    CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION->value,
                    CteDocumentStatusEnum::DRY_RUN_COMPLETED->value,
                ])
                    ->orWhere(fn ($query) => $query
                        ->whereIn('status', [
                            CteDocumentStatusEnum::CLAIMED->value,
                            CteDocumentStatusEnum::FILLING->value,
                            CteDocumentStatusEnum::VALIDATING->value,
                            CteDocumentStatusEnum::READY_TO_AUTHORIZE->value,
                            CteDocumentStatusEnum::AUTHORIZING->value,
                            CteDocumentStatusEnum::WAITING_FOR_XML->value,
                        ])
                        ->where('claim_expires_at', '<', now()));
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            GenerateCiotForBatchAction::make($this->getRecord()),
            Actions\Action::make('downloadDamdfe')
                ->label('Baixar DAMDFE')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->record->mdfeDocuments()
                    ->where('status', CteDocumentStatusEnum::AUTHORIZED->value)
                    ->whereNotNull('access_key')
                    ->whereNotNull('xml_path')
                    ->exists())
                ->action(function (): mixed {
                    $mdfe = $this->record->mdfeDocuments()
                        ->where('status', CteDocumentStatusEnum::AUTHORIZED->value)
                        ->whereNotNull('access_key')
                        ->whereNotNull('xml_path')
                        ->latest('id')
                        ->firstOrFail();
                    $pdfPath = $mdfe->pdf_path;

                    if (blank($pdfPath) || ! app(DfeDocumentStore::class)->exists($pdfPath)) {
                        $proc = app(DfeDocumentStore::class)->read((string) $mdfe->xml_path);
                        $pdfPath = app(GenerateDamdfePdf::class)->generateForProc(
                            (string) $mdfe->access_key,
                            $proc,
                        );
                        $mdfe->forceFill(['pdf_path' => $pdfPath])->save();
                    }

                    $filename = strtolower((string) $mdfe->access_key).'-damdfe.pdf';

                    return Storage::disk('dfe')->download((string) $pdfPath, $filename);
                }),
            Actions\Action::make('closeMdfe')
                ->label('Encerrar MDF-e')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->visible(fn (): bool => $this->mdfeAvailableForClosure() !== null)
                ->form([
                    Select::make('city_code')
                        ->label('Município real de encerramento')
                        ->placeholder('Pesquise por município ou UF')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => City::query()
                            ->where(fn ($query) => $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('state', 'like', "%{$search}%"))
                            ->orderBy('name')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (City $city): array => [$city->ibge_code => "{$city->name} - {$city->state}"])
                            ->all())
                        ->getOptionLabelUsing(function ($value): ?string {
                            $city = City::query()->where('ibge_code', $value)->first();

                            return $city === null ? null : "{$city->name} - {$city->state}";
                        })
                        ->required(),
                ])
                ->requiresConfirmation()
                ->modalHeading('Registrar encerramento do MDF-e')
                ->modalDescription('Informe o município onde a viagem efetivamente terminou. Após rejeição corrigível, o reenvio preserva a sequência e o Id fiscal, registrando uma nova tentativa local.')
                ->action(function (array $data): void {
                    $mdfe = $this->mdfeAvailableForClosure();

                    if ($mdfe === null) {
                        Notification::make()->title('Não há MDF-e disponível para encerramento ou reenvio seguro.')->warning()->send();

                        return;
                    }

                    $city = City::query()->where('ibge_code', $data['city_code'])->firstOrFail();
                    $event = app(CloseMdfeDocument::class)->handle($mdfe, $city);

                    Notification::make()
                        ->title(match ($event->status) {
                            'registered' => 'Encerramento registrado pela SEFAZ.',
                            'rejected_retryable' => 'Rejeição confirmada; corrija os dados e reenvie o encerramento.',
                            'duplicate_reconciliation' => 'Evento duplicado; reconcilie com a SEFAZ antes de qualquer ação.',
                            'already_closed' => 'A SEFAZ informou que o MDF-e já está encerrado.',
                            default => 'Resultado inconclusivo; consulte a situação na SEFAZ antes de reenviar.',
                        })
                        ->body($event->error_message)
                        ->color(match ($event->status) {
                            'registered' => 'success',
                            'rejected_retryable' => 'warning',
                            default => 'danger',
                        })
                        ->send();
                }),
            Actions\Action::make('reemitCiot')
                ->label('Reemitir CIOT')
                ->icon('heroicon-m-arrow-path')
                ->color('primary')
                ->visible(fn (): bool => $this->record->ciots()->where('status', CiotStatusEnum::FAILED->value)->exists())
                ->requiresConfirmation()
                ->modalHeading('Reemitir CIOT da viagem')
                ->modalDescription('Gera um novo IdOperacaoTransporte e reenvia a declaração à ANTT com os dados atuais do CIOT (edite-os pelo botão Editar do CIOT, se necessário).')
                ->action(function (): void {
                    /** @var Ciot|null $ciot */
                    $ciot = $this->record->ciots()
                        ->where('status', CiotStatusEnum::FAILED->value)
                        ->latest('id')
                        ->first();

                    if ($ciot === null) {
                        Notification::make()
                            ->title('Nenhum CIOT com falha neste lote.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $result = app(EmitCiotDeclaration::class)->emit($ciot);

                    CiotEmissionNotice::reemission()->send($result);

                    $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\Action::make('emitMdfe')
                ->label('Emitir MDF-e pelo agente (legado)')
                ->icon('heroicon-m-document-check')
                ->color('warning')
                ->visible(fn (): bool => config('dfe.mdfe.emitter', 'agent') === 'agent'
                    && in_array($this->record->refresh()->status?->value, ['completed', 'completed_with_errors'], true)
                    && $this->record->ciots()->where('status', CiotStatusEnum::ISSUED->value)->exists()
                    && ! $this->record->mdfeDocuments()
                        ->whereNotIn('status', ['rejected', 'failed_before_authorization', 'cancelled'])
                        ->exists())
                ->requiresConfirmation()
                ->modalHeading('Emitir MDF-e da viagem')
                ->modalDescription('Despacha o claim de MDF-e para o agente com o snapshot da viagem.')
                ->action(function (): void {
                    $mdfe = app(DispatchMdfeForBatch::class)->handle($this->record);

                    if ($mdfe === null) {
                        $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));

                        return;
                    }

                    Notification::make()
                        ->title('MDF-e enfileirado para o agente.')
                        ->success()
                        ->send();

                    $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\Action::make('emitMdfeDirect')
                ->label('Emitir MDF-e direto')
                ->icon('heroicon-m-paper-airplane')
                ->color('warning')
                ->visible(fn (): bool => config('dfe.mdfe.emitter') === 'direct'
                    && in_array($this->record->refresh()->status?->value, ['completed', 'completed_with_errors'], true)
                    && $this->ciotForDirectMdfe() !== null
                    && $this->record->documents()->exists()
                    && ! $this->record->documents()->where('status', '!=', CteDocumentStatusEnum::AUTHORIZED->value)->exists()
                    && ! $this->record->mdfeDocuments()
                        ->whereNotIn('status', ['rejected', 'failed_before_authorization', 'cancelled'])
                        ->exists())
                ->form([
                    Repeater::make('endorsements')
                        ->label('Averbações desta operação')
                        ->schema([
                            TextInput::make('number')
                                ->label('Número da averbação')
                                ->maxLength(40)
                                ->required(),
                        ])
                        ->minItems(1)
                        ->defaultItems(1),
                    Select::make('driver_id')
                        ->label('Motorista')
                        ->options(fn (): array => MdfeDriver::query()
                            ->where('line', CiotLineEnum::TankAlcohol->value)
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required(fn (): bool => $this->ciotForDirectMdfe()?->line === CiotLineEnum::TankAlcohol)
                        ->visible(fn (): bool => $this->ciotForDirectMdfe()?->line === CiotLineEnum::TankAlcohol),
                    Toggle::make('homologation_fixture')
                        ->label('Usar dados sintéticos de seguro para homologação')
                        ->helperText('Disponível apenas no ambiente de homologação; não salva esses dados na configuração.')
                        ->visible(fn (): bool => (string) config('dfe.tp_amb') === '2'),
                    Toggle::make('homologation_ciot_fixture')
                        ->label('Usar CIOT sintético para homologação')
                        ->helperText('A tentativa ANTT rejeitada com código 217 será preservada. O número sintético será usado somente no XML de homologação do MDF-e.')
                        ->visible(fn (): bool => (string) config('dfe.tp_amb') === '2'
                            && $this->issuedCiot() === null
                            && $this->ciotForDirectMdfe() !== null),
                    TextInput::make('fixture_ciot_number')
                        ->label('CIOT sintético (12 dígitos)')
                        ->default('999999999999')
                        ->length(12)
                        ->required(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_ciot_fixture'))
                        ->visible(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_ciot_fixture')),
                    TextInput::make('fixture_insurer_name')
                        ->label('Nome da seguradora de teste')
                        ->maxLength(30)
                        ->required(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_fixture'))
                        ->visible(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_fixture')),
                    TextInput::make('fixture_insurer_cnpj')
                        ->label('CNPJ de teste')
                        ->maxLength(14)
                        ->required(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_fixture'))
                        ->visible(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_fixture')),
                    TextInput::make('fixture_policy_number')
                        ->label('Apólice de teste')
                        ->maxLength(20)
                        ->required(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_fixture'))
                        ->visible(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_fixture')),
                ])
                ->requiresConfirmation()
                ->modalHeading('Emitir MDF-e direto na SEFAZ')
                ->modalDescription('O XML será assinado, validado e transmitido ao ambiente '.((string) config('dfe.tp_amb') === '1' ? 'de produção' : 'de homologação').'.')
                ->action(function (array $data): void {
                    $ciot = $this->ciotForDirectMdfe();

                    if ($ciot === null) {
                        Notification::make()->title('CIOT emitido não encontrado para esta viagem.')->danger()->send();

                        return;
                    }

                    $fixture = ($data['homologation_fixture'] ?? false) ? [
                        'insurer_name' => (string) $data['fixture_insurer_name'],
                        'insurer_cnpj' => (string) $data['fixture_insurer_cnpj'],
                        'policy_number' => (string) $data['fixture_policy_number'],
                    ] : null;

                    try {
                        $document = app(QueueDirectMdfeEmission::class)->handle(
                            batch: $this->record,
                            endorsements: collect($data['endorsements'] ?? [])->pluck('number')->all(),
                            routeUfs: [],
                            driver: isset($data['driver_id']) ? MdfeDriver::query()->findOrFail($data['driver_id']) : null,
                            homologationFixture: $fixture,
                            homologationCiotNumber: ($data['homologation_ciot_fixture'] ?? false)
                                ? (string) $data['fixture_ciot_number']
                                : null,
                        );
                    } catch (DomainException $exception) {
                        Notification::make()->title('MDF-e não foi enfileirado')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    EmitMdfeDirectJob::dispatch($document->id);

                    Notification::make()->title('MDF-e enviado à fila de emissão direta.')->success()->send();
                    $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\Action::make('requeueMdfe')
                ->label('Reenviar MDF-e')
                ->icon('heroicon-m-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => self::restartableMdfeDocuments()->exists())
                ->requiresConfirmation()
                ->modalHeading('Reenviar MDF-e')
                ->modalDescription('O documento volta para a fila do agente para nova tentativa.')
                ->action(function (): void {
                    self::restartableMdfeDocuments()
                        ->each(fn ($mdfe) => $mdfe->forceFill([
                            'status' => 'queued',
                            'claimed_by' => null,
                            'claim_token_hash' => null,
                            'claimed_at' => null,
                            'claim_expires_at' => null,
                            'error_stage' => null,
                            'error_code' => null,
                            'error_message' => null,
                            // Resultado da tentativa anterior: sem limpar o hash,
                            // toda nova tentativa esbarrava no 409 "a different
                            // result was already recorded".
                            'result_payload_hash' => null,
                            'issued_at' => null,
                            'authorized_at' => null,
                            'mdfe_number' => null,
                            'access_key' => null,
                            'series' => null,
                            'protocol' => null,
                            'fiscal_status_code' => null,
                            'fiscal_status_message' => null,
                        ])->save());

                    Notification::make()
                        ->title('MDF-e reenfileirado.')
                        ->success()
                        ->send();

                    $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\Action::make('reconcileMdfe')
                ->label('Conciliar MDF-e')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('success')
                ->visible(fn (): bool => $this->record->mdfeDocuments()
                    ->where('status', CteDocumentStatusEnum::RECONCILIATION_REQUIRED->value)
                    ->exists())
                ->form([
                    TextInput::make('access_key')
                        ->label('Chave de acesso do MDF-e (44 dígitos)')
                        ->mask(str_repeat('9', 44))
                        ->maxLength(44)
                        ->required(),
                    TextInput::make('protocol')
                        ->label('Protocolo de autorização (opcional)')
                        ->maxLength(60),
                ])
                ->modalHeading('Conciliar MDF-e da viagem')
                ->modalDescription('Use quando o agente autorizou o MDF-e no Lab mas não conseguiu reportar o resultado. O número e a série são derivados da chave de acesso.')
                ->action(function (array $data): void {
                    $mdfe = $this->record->mdfeDocuments()
                        ->where('status', CteDocumentStatusEnum::RECONCILIATION_REQUIRED->value)
                        ->latest('id')
                        ->first();

                    if ($mdfe === null) {
                        Notification::make()
                            ->title('Nenhum MDF-e aguardando conciliação neste lote.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        app(ReconcileMdfeDocument::class)->handle(
                            $mdfe,
                            (string) $data['access_key'],
                            filled($data['protocol'] ?? null) ? (string) $data['protocol'] : null,
                        );
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->title('Falha ao conciliar o MDF-e')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('MDF-e conciliado e marcado como autorizado.')
                        ->success()
                        ->send();

                    $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\Action::make('delete')
                ->label('Excluir lote')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status?->value === 'draft')
                ->requiresConfirmation()
                ->modalHeading('Excluir lote')
                ->modalDescription('Os documentos deste lote serão removidos. Os registros originais permanecerão disponíveis.')
                ->action(function (): void {
                    app(DeleteDraftCteEmissionBatch::class)->handle($this->record);
                    $this->redirect(CteEmissionBatchResource::getUrl('index'));
                })
                ->successNotificationTitle('Lote excluído.'),
            Actions\Action::make('approve')
                ->label('Aprovar lote')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status?->value === 'draft')
                ->requiresConfirmation()
                ->action(function (): void {
                    app(ApproveCteEmissionBatch::class)->handle($this->record, auth()->user());
                })
                ->successNotificationTitle('Lote aprovado e enviado para o agente.'),
        ];
    }
}
