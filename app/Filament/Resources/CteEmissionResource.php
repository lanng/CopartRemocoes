<?php

namespace App\Filament\Resources;

use App\Enums\CteDocumentStatusEnum;
use App\Filament\Resources\CteEmissionResource\Pages;
use App\Models\CteDocument;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Relação de CT-es emitidos (decisão F4): registros vivem independentes das
 * remoções — CT-e de tanque nem nasce de registro. Downloads do XML de guarda
 * (disk dfe) e do DACTE quando disponíveis; MDF-e entra aqui na F5.
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
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cte_number')->label('Número')->sortable(),
                TextColumn::make('series')->label('Série'),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge(),
                TextColumn::make('emitter')
                    ->label('Emissor')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'direct' ? 'success' : 'gray'),
                TextColumn::make('fiscal_status_code')->label('cStat'),
                TextColumn::make('fiscal_status_message')
                    ->label('Situação fiscal')
                    ->limit(45)
                    ->tooltip(fn (CteDocument $record): ?string => $record->fiscal_status_message),
                TextColumn::make('access_key')
                    ->label('Chave de acesso')
                    ->limit(20)
                    ->copyable()
                    ->tooltip(fn (CteDocument $record): ?string => $record->access_key),
                TextColumn::make('register.vehicle_plate')->label('Placa')->placeholder('—'),
                TextColumn::make('register.destination_city')->label('Destino')->placeholder('—'),
                TextColumn::make('protocol')->label('Protocolo')->placeholder('—'),
                TextColumn::make('authorized_at')
                    ->label('Autorizado em')
                    ->dateTime('d/m/Y H:i')
                    ->timezone('America/Sao_Paulo')
                    ->sortable()
                    ->placeholder('—'),
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
                self::downloadAction('downloadXml', 'XML', 'xml_path', '-cte.xml'),
                self::downloadAction('downloadPdf', 'PDF', 'pdf_path', '-dacte.pdf'),
            ])
            ->defaultSort('authorized_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('register'))
            ->persistFiltersInSession();
    }

    protected static function downloadAction(string $name, string $label, string $pathField, string $filenameSuffix): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (CteDocument $record): bool => filled($record->{$pathField}))
            ->action(function (CteDocument $record) use ($pathField, $filenameSuffix): void {
                $filename = strtolower((string) ($record->access_key ?? $record->id)).$filenameSuffix;

                Storage::disk('dfe')->download((string) $record->{$pathField}, $filename);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCteEmissions::route('/'),
        ];
    }
}
