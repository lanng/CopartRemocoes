<?php

namespace App\Filament\Resources;

use App\Enums\CiotLineEnum;
use App\Enums\CiotOperationTypeEnum;
use App\Enums\CiotStatusEnum;
use App\Filament\Resources\CiotResource\Pages;
use App\Models\Ciot;
use App\Models\CiotPayer;
use App\Models\CiotVehicle;
use App\Models\City;
use App\Services\Ciot\CancelCiot;
use App\Services\Ciot\CepLookup;
use App\Services\Ciot\CityDistanceCalculator;
use App\Services\Ciot\CloseCiot;
use App\Services\Ciot\EmitCiotDeclaration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CiotResource extends Resource
{
    protected static ?string $model = Ciot::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'CIOT';

    protected static ?string $navigationLabel = 'CIOTs';

    protected static ?string $modelLabel = 'CIOT';

    protected static ?string $pluralModelLabel = 'CIOTs';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('status')
                    ->default(CiotStatusEnum::DRAFT->value),
                Forms\Components\Section::make('Operação')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('line')
                            ->label('Linha')
                            ->options(self::lineOptions())
                            ->default(CiotLineEnum::VehicleRemoval->value)
                            ->live()
                            ->required(),
                        Forms\Components\Select::make('operation_type')
                            ->label('Tipo de operação')
                            ->options(self::operationTypeOptions())
                            ->default(CiotOperationTypeEnum::Lotation->value)
                            ->live()
                            ->required()
                            ->hintIcon('heroicon-m-information-circle')
                            ->hintIconTooltip(fn (Forms\Get $get): string => self::operationTypeHint($get)),
                        Forms\Components\Select::make('payer_id')
                            ->label('Contratante (pátio pagante)')
                            ->options(fn (): array => self::payerOptions())
                            ->searchable()
                            ->live()
                            ->required(),
                        Forms\Components\Select::make('delivery_payer_id')
                            ->label('Destinatário (pátio final da entrega)')
                            ->options(fn (): array => self::payerOptions())
                            ->searchable()
                            ->required(),
                        Forms\Components\CheckboxList::make('additional_payers')
                            ->label('Contratantes adicionais (demais pátios da viagem)')
                            ->options(fn (Forms\Get $get): array => self::payerOptions(excludeCnpj: CiotPayer::find($get('payer_id'))?->cnpj))
                            ->live()
                            ->visible(fn (Forms\Get $get): bool => $get('operation_type') === CiotOperationTypeEnum::Fractioned->value)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Rota')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Fieldset::make('Origem')
                            ->columns(2)
                            ->schema(self::locationFields('origin.')),
                        Forms\Components\Fieldset::make('Destino')
                            ->columns(2)
                            ->schema(self::locationFields('destination.')),
                        Forms\Components\TextInput::make('distance_km')
                            ->label('Distância (km)')
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->suffixAction(
                                Forms\Components\Actions\Action::make('calcularDistancia')
                                    ->icon('heroicon-m-calculator')
                                    ->label('Calcular distância')
                                    ->color('gray')
                                    ->action(function (Forms\Set $set, Forms\Get $get): void {
                                        self::calculateDistance($set, $get);
                                    }),
                            ),
                    ]),
                Forms\Components\Section::make('Carga e veículos')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('freight_value')
                            ->label('Valor do frete (total da viagem)')
                            ->numeric()
                            ->prefix('R$')
                            ->minValue(0.01)
                            ->required(),
                        Forms\Components\TextInput::make('cargo_weight_kg')
                            ->label('Peso da carga (kg)')
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\CheckboxList::make('vehicle_ids')
                            ->label('Veículos (1 automotor + reboques)')
                            ->options(fn (): array => self::vehicleOptions())
                            ->columns(2)
                            ->required()
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Viagem')
                    ->columns(2)
                    ->schema([
                        Forms\Components\DatePicker::make('travel_start_at')
                            ->label('Início da viagem')
                            ->default(today())
                            ->minDate(today())
                            ->required(),
                        Forms\Components\DatePicker::make('travel_end_at')
                            ->label('Fim da viagem')
                            ->minDate(fn (Forms\Get $get) => $get('travel_start_at') ?? today())
                            ->required(),
                    ]),
                Forms\Components\Section::make('Indicadores operacionais')
                    ->columns(3)
                    ->visible(fn (Forms\Get $get): bool => $get('operation_type') === CiotOperationTypeEnum::Lotation->value)
                    ->schema([
                        Forms\Components\Toggle::make('indicators.ind_alto_desempenho')
                            ->label('Alto desempenho')
                            ->default((bool) config('ciot.defaults.ind_alto_desempenho')),
                        Forms\Components\Toggle::make('indicators.ind_retorno_vazio')
                            ->label('Retorno vazio')
                            ->default((bool) config('ciot.defaults.ind_retorno_vazio')),
                        Forms\Components\Toggle::make('indicators.composicao_veicular')
                            ->label('Composição veicular')
                            ->default((bool) config('ciot.defaults.composicao_veicular')),
                    ]),
            ])
            ->columns(1);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Situação')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('status')
                            ->label('Situação')
                            ->badge()
                            ->color(fn (CiotStatusEnum $state): string => $state->color())
                            ->formatStateUsing(fn (CiotStatusEnum $state): string => $state->label()),
                        TextEntry::make('line')
                            ->label('Linha')
                            ->formatStateUsing(fn (CiotLineEnum $state): string => $state->label()),
                        TextEntry::make('operation_type')
                            ->label('Operação')
                            ->formatStateUsing(fn (CiotOperationTypeEnum $state): string => $state->label()),
                        TextEntry::make('created_at')
                            ->label('Criado em')
                            ->dateTime('d/m/Y H:i')
                            ->timezone('America/Sao_Paulo'),
                    ]),
                Section::make('Aviso ao transportador')
                    ->visible(fn (Ciot $record): bool => filled($record->carrier_notice))
                    ->schema([
                        TextEntry::make('carrier_notice')
                            ->hiddenLabel()
                            ->color('warning')
                            ->columnSpanFull(),
                    ]),
                Section::make('Emissão')
                    ->columns(4)
                    ->visible(fn (Ciot $record): bool => in_array($record->status, [CiotStatusEnum::ISSUED, CiotStatusEnum::CANCELED, CiotStatusEnum::CLOSED], true))
                    ->schema([
                        TextEntry::make('ciot_number')
                            ->label('CIOT')
                            ->copyable()
                            ->fontFamily('mono'),
                        TextEntry::make('full_number')
                            ->label('CIOT + verificador')
                            ->state(fn (Ciot $record): ?string => $record->fullNumber())
                            ->copyable()
                            ->fontFamily('mono'),
                        TextEntry::make('protocol')->label('Protocolo')->fontFamily('mono'),
                        TextEntry::make('id_operacao_transporte')->label('ID da operação')->fontFamily('mono'),
                        TextEntry::make('issued_at')
                            ->label('Emitido em')
                            ->dateTime('d/m/Y H:i')
                            ->timezone('America/Sao_Paulo'),
                    ]),
                Section::make('Contratantes')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('payer_name')
                            ->label('Contratante (pagante)')
                            ->state(fn (Ciot $record): string => sprintf('%s — %s', $record->payer_name, self::formatCnpj($record->payer_cnpj))),
                        TextEntry::make('delivery_payer_name')
                            ->label('Destinatário')
                            ->state(fn (Ciot $record): string => sprintf(
                                '%s — %s',
                                $record->delivery_payer_name ?? '—',
                                filled($record->delivery_payer_cnpj) ? self::formatCnpj($record->delivery_payer_cnpj) : '—',
                            )),
                        TextEntry::make('additional_payers')
                            ->label('Contratantes adicionais')
                            ->listWithLineBreaks()
                            ->formatStateUsing(fn (string $state): string => self::formatCnpj($state))
                            ->visible(fn (Ciot $record): bool => filled($record->additional_payers)),
                    ]),
                Section::make('Rota e carga')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('origin')
                            ->label('Origem')
                            ->state(fn (Ciot $record): string => self::formatLocation($record->origin ?? [])),
                        TextEntry::make('destination')
                            ->label('Destino')
                            ->state(fn (Ciot $record): string => self::formatLocation($record->destination ?? [])),
                        TextEntry::make('distance_km')
                            ->label('Distância')
                            ->suffix(' km'),
                        TextEntry::make('freight_value_cents')
                            ->label('Valor do frete')
                            ->money('BRL', divideBy: 100, locale: 'pt_BR'),
                        TextEntry::make('cargo_weight_kg')
                            ->label('Peso da carga')
                            ->suffix(' kg'),
                        TextEntry::make('vehicles')
                            ->label('Veículos')
                            ->state(fn (Ciot $record): string => collect($record->vehicles ?? [])
                                ->map(fn (array $vehicle): string => sprintf(
                                    '%s (%s, %d eixo(s))',
                                    $vehicle['placa'],
                                    $vehicle['tipo'] ?? 'automotor',
                                    $vehicle['eixos'],
                                ))
                                ->implode(', ')),
                        TextEntry::make('travel_start_at')
                            ->label('Início da viagem')
                            ->dateTime('d/m/Y H:i')
                            ->timezone('America/Sao_Paulo'),
                        TextEntry::make('travel_end_at')
                            ->label('Fim da viagem')
                            ->dateTime('d/m/Y H:i')
                            ->timezone('America/Sao_Paulo'),
                    ]),
                Section::make('Falha')
                    ->columns(2)
                    ->visible(fn (Ciot $record): bool => $record->status === CiotStatusEnum::FAILED)
                    ->schema([
                        TextEntry::make('error_code')->label('Código ANTT'),
                        TextEntry::make('error_message')->label('Mensagem ANTT')->columnSpanFull(),
                    ]),
                Section::make('Interações com a ANTT')
                    ->visible(fn (Ciot $record): bool => filled($record->cancel_reason) || filled($record->response))
                    ->collapsible()
                    ->schema([
                        TextEntry::make('cancel_reason')
                            ->label('Motivo do cancelamento')
                            ->visible(fn (Ciot $record): bool => filled($record->cancel_reason))
                            ->columnSpanFull(),
                        TextEntry::make('response')
                            ->label('Resposta bruta')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                            ->fontFamily('mono')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('15s')
            ->columns([
                Tables\Columns\TextColumn::make('ciot_number')
                    ->label('CIOT')
                    ->fontFamily('mono')
                    ->copyable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->color(fn (CiotStatusEnum $state): string => $state->color())
                    ->formatStateUsing(fn (CiotStatusEnum $state): string => $state->label()),
                Tables\Columns\TextColumn::make('line')
                    ->label('Linha')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (CiotLineEnum $state): string => $state->label()),
                Tables\Columns\TextColumn::make('payer_name')->label('Contratante')->searchable(),
                Tables\Columns\TextColumn::make('freight_value_cents')
                    ->label('Frete')
                    ->money('BRL', divideBy: 100, locale: 'pt_BR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('issued_at')
                    ->label('Emitido em')
                    ->dateTime('d/m/Y H:i')
                    ->timezone('America/Sao_Paulo')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->timezone('America/Sao_Paulo')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Situação')
                    ->options(collect(CiotStatusEnum::cases())->mapWithKeys(
                        fn (CiotStatusEnum $status): array => [$status->value => $status->label()]
                    )->all()),
                Tables\Filters\SelectFilter::make('line')
                    ->label('Linha')
                    ->options(collect(CiotLineEnum::cases())->mapWithKeys(
                        fn (CiotLineEnum $line): array => [$line->value => $line->label()]
                    )->all()),
                Tables\Filters\Filter::make('abertos')
                    ->label('Somente abertos')
                    ->query(fn (Builder $query): Builder => $query->where('status', CiotStatusEnum::ISSUED->value)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ver'),
                Tables\Actions\Action::make('emit')
                    ->label('Emitir')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->visible(fn (Ciot $record): bool => in_array($record->status, [CiotStatusEnum::DRAFT, CiotStatusEnum::FAILED], true))
                    ->requiresConfirmation()
                    ->modalHeading('Emitir CIOT na ANTT')
                    ->modalDescription('O CIOT será enviado para a ANTT em segundo plano com retry automático.')
                    ->action(function (Ciot $record): void {
                        app(EmitCiotDeclaration::class)->handle($record);
                    })
                    ->successNotificationTitle('Emissão enfileirada.'),
                Tables\Actions\Action::make('cancel')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Ciot $record): bool => $record->status === CiotStatusEnum::ISSUED)
                    ->form([
                        Forms\Components\Textarea::make('motivo')
                            ->label('Motivo (obrigatório, até 500 caracteres)')
                            ->maxLength(500)
                            ->required(),
                    ])
                    ->modalHeading('Cancelar CIOT')
                    ->action(function (Ciot $record, array $data): void {
                        app(CancelCiot::class)->handle($record, (string) $data['motivo']);
                    })
                    ->failureNotificationTitle('Falha ao cancelar.'),
                Tables\Actions\Action::make('close')
                    ->label('Encerrar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Ciot $record): bool => $record->status === CiotStatusEnum::ISSUED)
                    ->requiresConfirmation()
                    ->modalHeading('Encerrar CIOT')
                    ->modalDescription('O encerramento marca a operação como concluída na ANTT. É a rotina feita 1-2 dias após a emissão.')
                    ->action(function (Ciot $record): void {
                        app(CloseCiot::class)->handle($record);
                    })
                    ->successNotificationTitle('CIOT encerrado.')
                    ->failureNotificationTitle('Falha ao encerrar.'),
                Tables\Actions\DeleteAction::make()
                    ->label('Excluir')
                    ->visible(fn (Ciot $record): bool => in_array($record->status, [CiotStatusEnum::DRAFT, CiotStatusEnum::FAILED, CiotStatusEnum::CANCELED], true)),
            ])
            ->bulkActions([]);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) (Ciot::query()->where('status', CiotStatusEnum::ISSUED->value)->count() ?: null);
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCiots::route('/'),
            'create' => Pages\CreateCiot::route('/create'),
            'view' => Pages\ViewCiot::route('/{record}'),
        ];
    }

    /** @return array<string, string> */
    protected static function lineOptions(): array
    {
        return collect(CiotLineEnum::cases())->mapWithKeys(
            fn (CiotLineEnum $line): array => [$line->value => $line->label()]
        )->all();
    }

    /** @return array<string, string> */
    protected static function operationTypeOptions(): array
    {
        return [
            CiotOperationTypeEnum::Lotation->value => 'Lotação (um pátio)',
            CiotOperationTypeEnum::Fractioned->value => 'Fracionada (mais de um pátio)',
        ];
    }

    protected static function operationTypeHint(Forms\Get $get): string
    {
        if ($get('line') === CiotLineEnum::TankAlcohol->value) {
            return 'A linha do tanque opera sempre em lotação.';
        }

        return 'Fracionada quando a viagem tem mais de um pátio.';
    }

    /**
     * @return array<int|string, string>
     */
    protected static function payerOptions(?string $excludeCnpj = null): array
    {
        return CiotPayer::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (CiotPayer $payer): bool => $payer->cnpj !== $excludeCnpj)
            ->mapWithKeys(fn (CiotPayer $payer): array => [$payer->id => sprintf('%s — %s/%s', $payer->name, $payer->city, $payer->state)])
            ->all();
    }

    /** @return array<int|string, string> */
    protected static function vehicleOptions(): array
    {
        return CiotVehicle::query()
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('plate')
            ->get()
            ->mapWithKeys(fn (CiotVehicle $vehicle): array => [$vehicle->id => sprintf(
                '%s — %s, %d eixo(s)',
                $vehicle->plate,
                $vehicle->type,
                $vehicle->axles,
            )])
            ->all();
    }

    /**
     * Campos de localização com preenchimento assistido: CEP completa
     * cidade/UF/IBGE (BrasilAPI → ViaCEP) e o picker busca a cidade da
     * tabela local do IBGE por nome.
     *
     * @return list<Forms\Components\Component>
     */
    protected static function locationFields(string $prefix): array
    {
        return [
            Forms\Components\TextInput::make("{$prefix}cep")
                ->label('CEP')
                ->mask('99999-999')
                ->maxLength(9)
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => self::fillFromCep($set, $prefix, $state))
                ->suffixAction(
                    Forms\Components\Actions\Action::make('buscarCep')
                        ->icon('heroicon-m-magnifying-glass')
                        ->label('Buscar CEP')
                        ->color('gray')
                        ->action(function (Forms\Set $set, Forms\Get $get) use ($prefix): void {
                            self::fillFromCep($set, $prefix, $get("{$prefix}cep"));
                        }),
                ),
            Forms\Components\Select::make("{$prefix}city_picker")
                ->label('Autocompletar cidade')
                ->placeholder('Buscar pelo nome...')
                ->searchable()
                ->dehydrated(false)
                ->live()
                ->options(function (?string $search = null): array {
                    return City::query()
                        ->when(filled($search), fn ($query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('ibge_code', 'like', "%{$search}%"))
                        ->orderBy('name')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (City $city): array => [$city->ibge_code => "{$city->name} - {$city->state}"])
                        ->all();
                })
                ->afterStateUpdated(function (Forms\Set $set, ?string $state) use ($prefix): void {
                    if (blank($state)) {
                        return;
                    }

                    $city = City::query()->where('ibge_code', $state)->first();

                    if ($city !== null) {
                        $set("{$prefix}cidade", $city->name);
                        $set("{$prefix}uf", $city->state);
                        $set("{$prefix}ibge", $city->ibge_code);
                    }
                }),
            Forms\Components\TextInput::make("{$prefix}cidade")
                ->label('Cidade')
                ->required()
                ->maxLength(100),
            Forms\Components\TextInput::make("{$prefix}uf")
                ->label('UF')
                ->length(2)
                ->required(),
            Forms\Components\TextInput::make("{$prefix}ibge")
                ->label('Código IBGE (7 dígitos)')
                ->numeric()
                ->length(7)
                ->required(),
        ];
    }

    /**
     * Preenche cidade/UF/IBGE a partir do CEP. Falha é silenciosa: o campo
     * segue manual para o operador.
     */
    protected static function fillFromCep(Forms\Set $set, string $prefix, ?string $cep): void
    {
        $location = app(CepLookup::class)->lookup((string) $cep);

        if ($location === null) {
            return;
        }

        $set("{$prefix}cidade", $location['cidade']);
        $set("{$prefix}uf", $location['uf']);

        if (filled($location['ibge'])) {
            $set("{$prefix}ibge", $location['ibge']);
        }
    }

    protected static function calculateDistance(Forms\Set $set, Forms\Get $get): void
    {
        $origin = City::query()->where('ibge_code', $get('origin.ibge'))->first();
        $destination = City::query()->where('ibge_code', $get('destination.ibge'))->first();

        if ($origin === null || $destination === null
            || ! $origin->hasCoordinates() || ! $destination->hasCoordinates()) {
            Notification::make()
                ->title('Coordenadas indisponíveis')
                ->body('Informe os CEPs de origem e destino para buscar as coordenadas das cidades.')
                ->warning()
                ->send();

            return;
        }

        $km = app(CityDistanceCalculator::class)->calculate($origin, $destination);

        if ($km === null) {
            Notification::make()
                ->title('Não foi possível calcular a distância')
                ->body('Serviço de roteamento indisponível ou sem chave configurada (CIOT_DISTANCE_API_KEY).')
                ->warning()
                ->send();

            return;
        }

        $set('distance_km', $km);

        Notification::make()
            ->title("Distância sugerida: {$km} km")
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $location
     */
    protected static function formatLocation(array $location): string
    {
        $parts = array_filter([
            sprintf('%s/%s', $location['cidade'] ?? '', $location['uf'] ?? ''),
            filled($location['cep'] ?? null) ? 'CEP '.preg_replace('/(\d{5})(\d{3})/', '$1-$2', (string) $location['cep']) : null,
            filled($location['ibge'] ?? null) ? 'IBGE '.$location['ibge'] : null,
        ]);

        return implode(' · ', $parts);
    }

    protected static function formatCnpj(string $cnpj): string
    {
        if (strlen($cnpj) !== 14) {
            return $cnpj;
        }

        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($cnpj, 0, 2),
            substr($cnpj, 2, 3),
            substr($cnpj, 5, 3),
            substr($cnpj, 8, 4),
            substr($cnpj, 12, 2),
        );
    }
}
