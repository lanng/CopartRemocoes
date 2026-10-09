<?php

namespace App\Filament\Resources;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Filament\Resources\MdfeDocumentResource\Pages;
use App\Models\City;
use App\Models\MdfeDocument;
use App\Models\MdfeEvent;
use App\Services\Dfe\CloseTransportOperation;
use App\Services\Dfe\CloseTransportOperationResult;
use App\Services\Dfe\DfeDocumentStore;
use App\Services\Dfe\EditRejectedMdfeDocument;
use App\Services\Dfe\GenerateDamdfePdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MdfeDocumentResource extends Resource
{
    protected static ?string $model = MdfeDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'MDF-e';

    protected static ?string $navigationLabel = 'Manifestos';

    protected static ?string $modelLabel = 'MDF-e';

    protected static ?string $pluralModelLabel = 'Manifestos';

    public static function canCreate(): bool
    {
        return true;
    }

    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record)
            && $record instanceof MdfeDocument
            && app(EditRejectedMdfeDocument::class)->canEdit($record);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['batch', 'ciot', 'events', 'cteDocuments']);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->extraAttributes(['wire:poll.5s' => 'refreshDocument'])
            ->schema([
                InfolistSection::make('Identificação fiscal')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('mdfe_number')->label('Número')->placeholder('—'),
                        TextEntry::make('series')->label('Série')->placeholder('—'),
                        TextEntry::make('status')
                            ->label('Processamento')
                            ->badge()
                            ->formatStateUsing(fn (CteDocumentStatusEnum $state): string => $state->label())
                            ->color(fn (CteDocumentStatusEnum $state): string => $state->color()),
                        TextEntry::make('fiscal_status')
                            ->label('Situação fiscal')
                            ->badge()
                            ->formatStateUsing(fn (?FiscalDocumentStatusEnum $state): string => match ($state) {
                                FiscalDocumentStatusEnum::AUTHORIZED => 'Autorizado',
                                FiscalDocumentStatusEnum::CANCELLED => 'Cancelado',
                                FiscalDocumentStatusEnum::CLOSED => 'Encerrado',
                                default => 'Não autorizado',
                            }),
                        TextEntry::make('access_key')->label('Chave de acesso')->copyable()->placeholder('—')->columnSpanFull(),
                        TextEntry::make('fiscal_status_message')
                            ->label('Retorno da SEFAZ')
                            ->placeholder('Aguardando resposta da SEFAZ')
                            ->columnSpanFull(),
                        TextEntry::make('error_code')->label('Código do erro')->placeholder('—'),
                        TextEntry::make('error_message')->label('Detalhe do erro')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('protocol')->label('Protocolo')->placeholder('—'),
                        TextEntry::make('fiscal_status_code')->label('cStat')->placeholder('—'),
                        TextEntry::make('authorized_at')->label('Autorizado em')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo')->placeholder('—'),
                        TextEntry::make('batch_id')
                            ->label('Lote de origem')
                            ->state(fn (MdfeDocument $record): string => $record->cte_emission_batch_id === null ? 'Sem lote' : "#{$record->cte_emission_batch_id}"),
                        TextEntry::make('ciot_number')
                            ->label('CIOT vinculado')
                            ->state(fn (MdfeDocument $record): string => $record->ciot?->fullNumber() ?? '—'),
                        TextEntry::make('ciot_status')
                            ->label('Situação do CIOT vinculado')
                            ->state(fn (MdfeDocument $record): string => $record->ciot?->status?->label() ?? '—'),
                    ]),
                InfolistSection::make('Retorno da ANTT')
                    ->visible(fn (MdfeDocument $record): bool => filled($record->ciot?->response)
                        || filled($record->ciot?->error_message))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('ciot.error_code')->label('Código ANTT')->placeholder('—'),
                        TextEntry::make('ciot.error_message')->label('Mensagem ANTT')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('ciot.response')
                            ->label('Resposta da ANTT')
                            ->state(fn (MdfeDocument $record): ?string => filled($record->ciot?->response)
                                ? json_encode($record->ciot->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: null
                                : null)
                            ->fontFamily('mono')
                            ->columnSpanFull(),
                    ]),
                InfolistSection::make('Operação')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('snapshot.line')
                            ->label('Modalidade')
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'vehicle_removal' => 'Remoção de veículos',
                                'tank_alcohol' => 'Tanque de álcool',
                                default => $state ?? '—',
                            }),
                        TextEntry::make('snapshot.origin.name')->label('Origem')->placeholder('—'),
                        TextEntry::make('snapshot.destination.name')->label('Destino efetivo')->placeholder('—'),
                        TextEntry::make('snapshot.cargo.value')->label('Valor da carga')->money('BRL')->placeholder('—'),
                        TextEntry::make('snapshot.cargo.weight_kg')->label('Peso da carga')->suffix(' kg')->placeholder('—'),
                        TextEntry::make('snapshot.payment.contract_value')->label('Frete')->money('BRL')->placeholder('—'),
                    ]),
                InfolistSection::make('CT-es vinculados')
                    ->schema([
                        RepeatableEntry::make('cteDocuments')
                            ->label('Documentos fiscais')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('cte_number')->label('Número')->placeholder('—'),
                                TextEntry::make('series')->label('Série')->placeholder('—'),
                                TextEntry::make('status')
                                    ->label('Processamento')
                                    ->formatStateUsing(fn (CteDocumentStatusEnum $state): string => $state->label()),
                                TextEntry::make('fiscal_status')
                                    ->label('Situação fiscal')
                                    ->formatStateUsing(fn (?FiscalDocumentStatusEnum $state): string => match ($state) {
                                        FiscalDocumentStatusEnum::AUTHORIZED => 'Autorizado',
                                        FiscalDocumentStatusEnum::CANCELLED => 'Cancelado',
                                        FiscalDocumentStatusEnum::CLOSED => 'Encerrado',
                                        default => 'Não autorizado',
                                    }),
                                TextEntry::make('fiscal_status_code')->label('cStat SEFAZ')->placeholder('—'),
                                TextEntry::make('authorized_at')->label('Autorizado em')->dateTime('d/m/Y H:i')->placeholder('—'),
                                TextEntry::make('access_key')->label('Chave de acesso')->copyable()->columnSpanFull(),
                            ]),
                    ]),
                InfolistSection::make('Eventos fiscais')
                    ->schema([
                        RepeatableEntry::make('events')
                            ->label('Histórico de eventos')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('event_type')->label('Tipo'),
                                TextEntry::make('attempt')->label('Tentativa'),
                                TextEntry::make('status')->label('Resultado')->badge(),
                                TextEntry::make('protocol')->label('Protocolo')->placeholder('—'),
                                TextEntry::make('error_code')->label('Código')->placeholder('—'),
                                TextEntry::make('error_message')->label('Mensagem')->placeholder('—')->columnSpanFull(),
                                TextEntry::make('created_at')->label('Registrado em')->dateTime('d/m/Y H:i:s'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['batch', 'ciot', 'events']))
            ->columns([
                Tables\Columns\TextColumn::make('mdfe_number')
                    ->label('Número')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('snapshot.line')
                    ->label('Operação')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'vehicle_removal' => 'Remoção de veículos',
                        'tank_alcohol' => 'Tanque de álcool',
                        default => $state ?? '—',
                    })
                    ->badge(),
                Tables\Columns\TextColumn::make('access_key')
                    ->label('Chave de acesso')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('batch.id')
                    ->label('Lote')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : "#{$state}"),
                Tables\Columns\TextColumn::make('ciot.ciot_number')
                    ->label('CIOT')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Processamento')
                    ->badge()
                    ->formatStateUsing(fn (CteDocumentStatusEnum $state): string => $state->label())
                    ->color(fn (CteDocumentStatusEnum $state): string => $state->color())
                    ->sortable(),
                Tables\Columns\TextColumn::make('fiscal_status')
                    ->label('Situação fiscal')
                    ->badge()
                    ->formatStateUsing(fn (?FiscalDocumentStatusEnum $state): string => match ($state) {
                        FiscalDocumentStatusEnum::AUTHORIZED => 'Autorizado',
                        FiscalDocumentStatusEnum::CANCELLED => 'Cancelado',
                        FiscalDocumentStatusEnum::CLOSED => 'Encerrado',
                        default => 'Não autorizado',
                    })
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('protocol')
                    ->label('Protocolo')
                    ->fontFamily('mono')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('fiscal_status_code')
                    ->label('Situação SEFAZ')
                    ->badge()
                    ->color(fn (?string $state): string => $state === '132' ? 'success' : 'warning')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        '100' => 'Autorizado / aberto',
                        '132' => 'Encerrado',
                        default => $state ?? 'Aguardando confirmação',
                    }),
                Tables\Columns\TextColumn::make('authorized_at')
                    ->label('Autorizado em')
                    ->dateTime('d/m/Y H:i')
                    ->timezone('America/Sao_Paulo')
                    ->sortable(),
                Tables\Columns\TextColumn::make('closure_attempt')
                    ->label('Última tentativa')
                    ->state(function (MdfeDocument $record): string {
                        $event = $record->events
                            ->where('event_type', '110112')
                            ->sortByDesc('attempt')
                            ->first();

                        return $event === null
                            ? 'Não enviado'
                            : "#{$event->attempt} — {$event->status}".($event->error_code ? " ({$event->error_code})" : '');
                    })
                    ->placeholder('Não enviado'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Processamento')
                    ->options(collect(CteDocumentStatusEnum::cases())
                        ->mapWithKeys(fn (CteDocumentStatusEnum $status): array => [$status->value => $status->label()])
                        ->all()),
                Tables\Filters\SelectFilter::make('fiscal_status')
                    ->label('Situação fiscal')
                    ->options([
                        FiscalDocumentStatusEnum::NOT_AUTHORIZED->value => 'Não autorizado',
                        FiscalDocumentStatusEnum::AUTHORIZED->value => 'Autorizado',
                        FiscalDocumentStatusEnum::CANCELLED->value => 'Cancelado',
                        FiscalDocumentStatusEnum::CLOSED->value => 'Encerrado',
                    ]),
                Tables\Filters\SelectFilter::make('fiscal_status_code')
                    ->label('Situação SEFAZ')
                    ->options([
                        '100' => 'Autorizado / aberto',
                        '101' => 'Cancelado',
                        '132' => 'Encerrado',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ver MDF-e'),
                Tables\Actions\Action::make('closeMdfe')
                    ->label(fn (MdfeDocument $record): string => self::closureActionLabel($record))
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->visible(fn (MdfeDocument $record): bool => $record->isClosureAvailable())
                    ->form([
                        Forms\Components\Select::make('city_code')
                            ->label('Município real de encerramento')
                            ->placeholder('Pesquise por município ou UF')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => City::query()
                                ->where(fn (Builder $query) => $query
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
                    ->modalHeading('Encerrar operação de transporte')
                    ->action(function (MdfeDocument $record, array $data): void {
                        $city = City::query()->where('ibge_code', $data['city_code'])->firstOrFail();

                        self::runTransportClosure($record, $city);
                    }),
                Tables\Actions\Action::make('resumeCiotClosure')
                    ->label('Retomar encerramento do CIOT')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (MdfeDocument $record): bool => (string) $record->fiscal_status_code === '132'
                        && $record->ciot?->status === CiotStatusEnum::ISSUED)
                    ->requiresConfirmation()
                    ->modalHeading('Retomar somente o encerramento do CIOT')
                    ->modalDescription('A SEFAZ já confirmou o encerramento do MDF-e. Esta ação tenta somente o encerramento do CIOT na ANTT.')
                    ->action(function (MdfeDocument $record): void {
                        self::runTransportClosure($record);
                    }),
                Tables\Actions\Action::make('downloadXml')
                    ->label('Baixar XML')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (MdfeDocument $record): bool => filled($record->xml_path)
                        && app(DfeDocumentStore::class)->exists((string) $record->xml_path))
                    ->action(fn (MdfeDocument $record): StreamedResponse => Storage::disk('dfe')->download(
                        (string) $record->xml_path,
                        strtolower((string) ($record->access_key ?? $record->id)).'-mdfe.xml',
                    )),
                Tables\Actions\Action::make('downloadDamdfe')
                    ->label('Baixar DAMDFE')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (MdfeDocument $record): bool => (filled($record->pdf_path)
                        && app(DfeDocumentStore::class)->exists((string) $record->pdf_path))
                        || (filled($record->xml_path)
                            && app(DfeDocumentStore::class)->exists((string) $record->xml_path)))
                    ->action(function (MdfeDocument $record): StreamedResponse {
                        $pdfPath = $record->pdf_path;

                        if (blank($pdfPath) || ! app(DfeDocumentStore::class)->exists($pdfPath)) {
                            $proc = app(DfeDocumentStore::class)->read((string) $record->xml_path);
                            $pdfPath = app(GenerateDamdfePdf::class)->generateForProc(
                                (string) $record->access_key,
                                $proc,
                            );
                            $record->forceFill(['pdf_path' => $pdfPath])->save();
                        }

                        return Storage::disk('dfe')->download(
                            (string) $pdfPath,
                            strtolower((string) $record->access_key).'-damdfe.pdf',
                        );
                    }),
                Tables\Actions\Action::make('downloadEventXml')
                    ->label('Baixar evento XML')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (MdfeDocument $record): bool => self::archivedEventOptions($record) !== [])
                    ->form(fn (MdfeDocument $record): array => [
                        Forms\Components\Select::make('event_id')
                            ->label('Evento fiscal')
                            ->options(self::archivedEventOptions($record))
                            ->required(),
                    ])
                    ->action(function (MdfeDocument $record, array $data): StreamedResponse {
                        $event = $record->events()->whereKey($data['event_id'])->firstOrFail();
                        abort_unless(filled($event->xml_path)
                            && app(DfeDocumentStore::class)->exists((string) $event->xml_path), 404);

                        return Storage::disk('dfe')->download(
                            (string) $event->xml_path,
                            basename((string) $event->xml_path),
                        );
                    }),
                Tables\Actions\Action::make('viewBatch')
                    ->label('Ver lote')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (MdfeDocument $record): bool => $record->cte_emission_batch_id !== null)
                    ->url(fn (MdfeDocument $record): string => CteEmissionBatchResource::getUrl('view', ['record' => $record->cte_emission_batch_id])),
            ])
            ->defaultSort('created_at', 'desc')
            ->bulkActions([]);
    }

    /** @return array<int, string> */
    private static function archivedEventOptions(MdfeDocument $record): array
    {
        return $record->events
            ->filter(fn (MdfeEvent $event): bool => filled($event->xml_path)
                && app(DfeDocumentStore::class)->exists((string) $event->xml_path))
            ->sortByDesc('created_at')
            ->mapWithKeys(fn (MdfeEvent $event): array => [
                $event->id => sprintf('%s · tentativa %s · %s', $event->event_type, $event->attempt, $event->status),
            ])
            ->all();
    }

    private static function closureActionLabel(MdfeDocument $record): string
    {
        $latestAttempt = $record->events
            ->where('event_type', '110112')
            ->sortByDesc('attempt')
            ->first();

        if (in_array($latestAttempt?->status, ['pending', 'registered', 'reconciliation_required', 'duplicate_reconciliation'], true)) {
            return 'Reconciliar encerramento';
        }

        return $latestAttempt?->isRetryableRejection() === true
            ? 'Reenviar encerramento'
            : 'Encerrar operação de transporte';
    }

    private static function runTransportClosure(MdfeDocument $record, ?City $city = null): void
    {
        try {
            $result = app(CloseTransportOperation::class)->handle($record, $city);
        } catch (\DomainException $exception) {
            Notification::make()
                ->title('Não foi possível encerrar a operação de transporte.')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(match ($result->status) {
                CloseTransportOperationResult::COMPLETED => $result->mdfe->ciot_id === null
                    ? 'MDF-e encerrado pela SEFAZ.'
                    : 'MDF-e e CIOT encerrados.',
                CloseTransportOperationResult::MDFE_PENDING => 'Aguardando confirmação do encerramento pela SEFAZ.',
                default => 'MDF-e encerrado; encerramento do CIOT pendente na ANTT.',
            })
            ->body($result->message)
            ->color($result->status === CloseTransportOperationResult::COMPLETED ? 'success' : 'warning')
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMdfeDocuments::route('/'),
            'create' => Pages\CreateMdfeDocument::route('/create'),
            'edit' => Pages\EditMdfeDocument::route('/{record}/edit'),
            'view' => Pages\ViewMdfeDocument::route('/{record}'),
        ];
    }
}
