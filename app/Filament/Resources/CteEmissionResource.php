<?php

namespace App\Filament\Resources;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Filament\Resources\CteEmissionResource\Pages;
use App\Models\CteDocument;
use App\Services\Dfe\DfeDocumentStore;
use App\Services\Dfe\GenerateDactePdf;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CT-es emitidos vivem independentes das remoções — CT-e de tanque nem nasce
 * de registro. Arquivos fiscais disponíveis são servidos do disk dfe.
 */
class CteEmissionResource extends Resource
{
    protected static ?string $model = CteDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'CT-e';

    protected static ?string $navigationLabel = 'Emissões';

    protected static ?string $modelLabel = 'CT-e emitido';

    protected static ?string $pluralModelLabel = 'CT-es emitidos';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->extraAttributes(['wire:poll.5s' => 'refreshDocument'])
            ->schema([
                Section::make('Situação do CT-e')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('cte_number')->label('Número')->placeholder('Aguardando emissão'),
                        TextEntry::make('series')->label('Série')->placeholder('—'),
                        TextEntry::make('status')
                            ->label('Processamento')
                            ->badge()
                            ->color(fn (CteDocumentStatusEnum $state): string => $state->color())
                            ->formatStateUsing(fn (CteDocumentStatusEnum $state): string => $state->label()),
                        TextEntry::make('access_key')->label('Chave de acesso')->copyable()->placeholder('—')->columnSpanFull(),
                        TextEntry::make('fiscal_status_code')->label('cStat')->placeholder('—'),
                        TextEntry::make('fiscal_status_message')->label('Situação fiscal')->placeholder('Aguardando processamento')->columnSpan(2),
                        TextEntry::make('error_code')->label('Código de erro')->placeholder('—'),
                        TextEntry::make('error_message')->label('Detalhe do erro')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('protocol')->label('Protocolo')->placeholder('—'),
                        TextEntry::make('authorized_at')->label('Autorizado em')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo')->placeholder('—'),
                    ]),
                Section::make('Origem e destino')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('origin')
                            ->label('Origem da carga')
                            ->state(fn (CteDocument $record): string => self::formatCity((array) data_get($record->snapshot, 'origin', []))),
                        TextEntry::make('destination')
                            ->label('Destino da carga')
                            ->state(fn (CteDocument $record): string => self::formatCity((array) data_get($record->snapshot, 'destination', []))),
                        TextEntry::make('modality')
                            ->label('Modalidade')
                            ->state(fn (CteDocument $record): string => self::formatModality(data_get($record->snapshot, 'modal'))),
                        TextEntry::make('snapshot.dprev')->label('Previsão de entrega')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('snapshot.dest.xNome')->label('Tomador fiscal')->placeholder('—'),
                    ]),
                Section::make('Entrega física')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('snapshot.delivery_party.xNome')->label('Destinatário físico')->placeholder('—'),
                        TextEntry::make('snapshot.delivery_party.cnpj')->label('CNPJ')->placeholder('—'),
                        TextEntry::make('snapshot.delivery_party.cpf')->label('CPF')->placeholder('—'),
                        TextEntry::make('snapshot.delivery_party.xLgr')->label('Logradouro')->placeholder('—'),
                        TextEntry::make('snapshot.delivery_party.nro')->label('Número')->placeholder('—'),
                        TextEntry::make('snapshot.delivery_party.xCpl')->label('Complemento')->placeholder('—'),
                        TextEntry::make('snapshot.delivery_party.xBairro')->label('Bairro')->placeholder('—'),
                        TextEntry::make('snapshot.delivery_party.xMun')->label('Município')->placeholder('—'),
                        TextEntry::make('snapshot.delivery_party.uf')->label('UF')->placeholder('—'),
                        TextEntry::make('snapshot.delivery_party.cep')->label('CEP')->placeholder('—'),
                        TextEntry::make('delivery_missing_fields')
                            ->label('Dados de entrega pendentes')
                            ->state(fn (CteDocument $record): string => implode(', ', array_map('strval', (array) data_get($record->snapshot, 'delivery_missing_fields', []))))
                            ->placeholder('Nenhum')
                            ->columnSpanFull(),
                    ]),
                Section::make('Valores e carga')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('snapshot.value')->label('Frete')->money('BRL')->placeholder('—'),
                        TextEntry::make('snapshot.cargo_value')->label('Valor da carga')->money('BRL')->placeholder('—'),
                        TextEntry::make('snapshot.cargo_liters')->label('Volume')->suffix(' L')->placeholder('—'),
                        TextEntry::make('snapshot.cargo_weight_kg')->label('Peso líquido')->suffix(' kg')->placeholder('—'),
                    ]),
                Section::make('NF-es vinculadas')
                    ->schema([
                        RepeatableEntry::make('nfeDocuments')
                            ->label('Notas fiscais')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('number')->label('Número'),
                                TextEntry::make('series')->label('Série'),
                                TextEntry::make('source_filename')->label('Arquivo original'),
                                TextEntry::make('access_key')->label('Chave de acesso')->copyable()->columnSpanFull(),
                                TextEntry::make('extracted_data.cargo_value')->label('Valor')->money('BRL'),
                                TextEntry::make('extracted_data.cargo_liters')->label('Volume')->suffix(' L'),
                                TextEntry::make('extracted_data.net_weight_kg')->label('Peso líquido')->suffix(' kg'),
                            ]),
                    ]),
                Section::make('MDF-es vinculados')
                    ->schema([
                        RepeatableEntry::make('mdfeDocuments')
                            ->label('Manifestos')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('mdfe_number')->label('Número')->placeholder('—'),
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
                                TextEntry::make('access_key')->label('Chave de acesso')->copyable()->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('cte_number')
                            ->label('Número')
                            ->description('Número do CT-e', position: 'above')
                            ->weight(FontWeight::Bold)
                            ->sortable(),
                        TextColumn::make('status')
                            ->label('Situação')
                            ->description('Processamento', position: 'above')
                            ->badge(),
                        TextColumn::make('emitter')
                            ->label('Emissor')
                            ->description('Emissor', position: 'above')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'direct' ? 'success' : 'gray'),
                        TextColumn::make('register.vehicle_plate')
                            ->label('Placa')
                            ->description('Placa', position: 'above')
                            ->placeholder('—'),
                        TextColumn::make('authorized_at')
                            ->label('Autorizado em')
                            ->description('Autorizado em', position: 'above')
                            ->dateTime('d/m/Y H:i')
                            ->timezone('America/Sao_Paulo')
                            ->sortable()
                            ->placeholder('—'),
                    ])->space(2),
                    Stack::make([
                        TextColumn::make('origin')
                            ->label('Origem')
                            ->description('Origem', position: 'above')
                            ->state(fn (CteDocument $record): string => self::formatCity((array) data_get($record->snapshot, 'origin', [])))
                            ->wrap()
                            ->placeholder('—'),
                        TextColumn::make('destination')
                            ->label('Destino')
                            ->description('Destino', position: 'above')
                            ->state(fn (CteDocument $record): string => self::formatCity((array) data_get($record->snapshot, 'destination', [])))
                            ->wrap()
                            ->placeholder('—'),
                        TextColumn::make('snapshot.value')
                            ->label('Valor CT-e')
                            ->description('Valor CT-e', position: 'above')
                            ->money('BRL')
                            ->placeholder('—'),
                    ])->space(2),
                ])->from('2xl'),
            ])
            ->filters([
                SelectFilter::make('emitter')
                    ->label('Emissor')
                    ->options(['direct' => 'Sistema (SEFAZ direta)', 'lab' => 'Lab']),
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options(collect(CteDocumentStatusEnum::cases())->mapWithKeys(
                        fn (CteDocumentStatusEnum $status): array => [$status->value => $status->label()],
                    )->all()),
            ])
            ->actions([
                Action::make('regenerateDacte')
                    ->label('Regenerar DACTE')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->label('Regenerar e baixar DACTE')
                    ->visible(fn (CteDocument $record): bool => filled($record->xml_path))
                    ->action(function (CteDocument $record): ?StreamedResponse {
                        $store = app(DfeDocumentStore::class);

                        if (! filled($record->xml_path) || ! $store->exists((string) $record->xml_path)) {
                            self::notifyMissingArchivedFile();

                            return null;
                        }

                        $proc = $store->read((string) $record->xml_path);
                        $pdfPath = app(GenerateDactePdf::class)->generateForProc((string) $record->access_key, $proc);

                        $record->forceFill(['pdf_path' => $pdfPath])->save();

                        return Storage::disk('dfe')->download(
                            $pdfPath,
                            strtolower((string) ($record->access_key ?? $record->id)).'-dacte.pdf',
                        );
                    }),
                self::downloadAction('downloadXml', 'XML', 'xml_path', '-cte.xml'),
                Action::make('downloadPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (CteDocument $record): bool => filled($record->pdf_path) || filled($record->xml_path))
                    ->action(fn (CteDocument $record): ?StreamedResponse => self::downloadPdf($record)),
            ])
            ->defaultSort('authorized_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select([
                    'id',
                    'register_id',
                    'cte_number',
                    'status',
                    'emitter',
                    'access_key',
                    'snapshot',
                    'authorized_at',
                    'xml_path',
                    'pdf_path',
                ])
                ->with(['register:id,vehicle_plate']))
            ->persistFiltersInSession();
    }

    protected static function downloadAction(string $name, string $label, string $pathField, string $filenameSuffix): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (CteDocument $record): bool => filled($record->{$pathField}))
            ->action(function (CteDocument $record) use ($pathField, $filenameSuffix): ?StreamedResponse {
                $path = (string) $record->{$pathField};

                if (! self::hasArchivedFile($path)) {
                    self::notifyMissingArchivedFile();

                    return null;
                }

                $filename = strtolower((string) ($record->access_key ?? $record->id)).$filenameSuffix;

                return Storage::disk('dfe')->download($path, $filename);
            });
    }

    private static function downloadPdf(CteDocument $record): ?StreamedResponse
    {
        $store = app(DfeDocumentStore::class);
        $pdfPath = (string) $record->pdf_path;

        if (! filled($pdfPath) || ! $store->exists($pdfPath)) {
            if (! self::hasArchivedFile($record->xml_path)) {
                self::notifyMissingArchivedFile();

                return null;
            }

            $pdfPath = app(GenerateDactePdf::class)->generateForProc(
                (string) $record->access_key,
                $store->read((string) $record->xml_path),
            );
            $record->forceFill(['pdf_path' => $pdfPath])->save();
        }

        return Storage::disk('dfe')->download(
            $pdfPath,
            strtolower((string) ($record->access_key ?? $record->id)).'-dacte.pdf',
        );
    }

    private static function hasArchivedFile(?string $path): bool
    {
        return filled($path) && app(DfeDocumentStore::class)->exists($path);
    }

    private static function notifyMissingArchivedFile(): void
    {
        Notification::make()
            ->title('Arquivo fiscal indisponível')
            ->body('O arquivo não foi encontrado no armazenamento. Atualize a lista ou solicite a recuperação do documento.')
            ->warning()
            ->send();
    }

    /** @param array<string, mixed> $city */
    private static function formatCity(array $city): string
    {
        return trim(implode(' / ', array_filter([
            (string) ($city['name'] ?? ''),
            (string) ($city['uf'] ?? ''),
        ])));
    }

    private static function formatModality(mixed $modality): string
    {
        return match ((string) $modality) {
            '01' => 'Rodoviário',
            '02' => 'Aéreo',
            '03' => 'Aquaviário',
            '04' => 'Ferroviário',
            '05' => 'Dutoviário',
            '06' => 'Multimodal',
            default => '—',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCteEmissions::route('/'),
            'create' => Pages\CreateCteEmission::route('/create'),
            'view' => Pages\ViewCteEmission::route('/{record}'),
        ];
    }
}
