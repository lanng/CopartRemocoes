<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MdfeDocumentResource\Pages;
use App\Models\City;
use App\Models\MdfeDocument;
use App\Services\Dfe\CloseMdfeDocument;
use App\Services\Dfe\DfeDocumentStore;
use App\Services\Dfe\GenerateDamdfePdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class MdfeDocumentResource extends Resource
{
    protected static ?string $model = MdfeDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'MDF-e';

    protected static ?string $navigationLabel = 'Manifestos abertos';

    protected static ?string $modelLabel = 'MDF-e aberto';

    protected static ?string $pluralModelLabel = 'Manifestos abertos';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->openForClosure();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['batch', 'events']))
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
                Tables\Filters\SelectFilter::make('fiscal_status_code')
                    ->label('Situação SEFAZ')
                    ->options([
                        '100' => 'Autorizado / aberto',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('closeMdfe')
                    ->label(fn (MdfeDocument $record): string => $record->events
                        ->where('event_type', '110112')
                        ->isNotEmpty() ? 'Reenviar encerramento' : 'Encerrar MDF-e')
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
                    ->modalHeading('Registrar encerramento do MDF-e')
                    ->action(function (MdfeDocument $record, array $data): void {
                        $city = City::query()->where('ibge_code', $data['city_code'])->firstOrFail();
                        $event = app(CloseMdfeDocument::class)->handle($record, $city);

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
                Tables\Actions\Action::make('downloadDamdfe')
                    ->label('Baixar DAMDFE')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (MdfeDocument $record): bool => filled($record->xml_path))
                    ->action(function (MdfeDocument $record): mixed {
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
                Tables\Actions\Action::make('viewBatch')
                    ->label('Ver lote')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (MdfeDocument $record): string => CteEmissionBatchResource::getUrl('view', ['record' => $record->cte_emission_batch_id])),
            ])
            ->defaultSort('authorized_at', 'desc')
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMdfeDocuments::route('/'),
        ];
    }
}
