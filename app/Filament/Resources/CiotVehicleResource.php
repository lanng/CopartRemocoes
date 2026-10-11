<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CiotVehicleResource\Pages;
use App\Models\CiotVehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CiotVehicleResource extends Resource
{
    protected static ?string $model = CiotVehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'CIOT';

    protected static ?string $navigationLabel = 'Frota';

    protected static ?string $modelLabel = 'Veículo da frota';

    protected static ?string $pluralModelLabel = 'Frota';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('plate')
                    ->label('Placa')
                    ->required()
                    ->length(7)
                    ->unique(ignoreRecord: true)
                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                    ->dehydrateStateUsing(fn (string $state): string => strtoupper(trim($state))),
                Forms\Components\TextInput::make('rntrc')
                    ->label('RNTRC')
                    ->numeric()
                    ->length(9),
                Forms\Components\TextInput::make('renavam')
                    ->label('RENAVAM')
                    ->numeric()
                    ->minLength(9)
                    ->maxLength(11),
                Forms\Components\TextInput::make('tare_kg')
                    ->label('Tara (kg)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999999)
                    ->required(fn (Forms\Get $get): bool => filled($get('type'))),
                Forms\Components\TextInput::make('capacity_kg')
                    ->label('Capacidade (kg)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999999)
                    ->required(fn (Forms\Get $get): bool => $get('type') === CiotVehicle::TYPE_TRAILER),
                Forms\Components\TextInput::make('capacity_m3')
                    ->label('Capacidade (m³)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999),
                Forms\Components\Select::make('type_rod')
                    ->label('Tipo de rodado')
                    ->options([
                        '01' => 'Truck',
                        '02' => 'Toco',
                        '03' => 'Cavalo mecânico',
                        '04' => 'Van',
                        '05' => 'Utilitário',
                        '06' => 'Outros',
                    ])
                    ->required(fn (Forms\Get $get): bool => $get('type') === CiotVehicle::TYPE_AUTOMOTOR),
                Forms\Components\Select::make('body_type')
                    ->label('Tipo de carroceria')
                    ->options([
                        '00' => 'Não aplicável',
                        '01' => 'Aberta',
                        '02' => 'Fechada / baú',
                        '03' => 'Graneleira',
                        '04' => 'Porta-container',
                        '05' => 'Sider',
                    ])
                    ->required(fn (Forms\Get $get): bool => filled($get('type'))),
                Forms\Components\TextInput::make('uf')
                    ->label('UF do licenciamento')
                    ->length(2)
                    ->maxLength(2)
                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper(trim($state)) : null),
                Forms\Components\TextInput::make('axles')
                    ->label('Eixos')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(4)
                    ->required(),
                Forms\Components\Select::make('type')
                    ->label('Tipo')
                    ->options([
                        CiotVehicle::TYPE_AUTOMOTOR => 'Automotor',
                        CiotVehicle::TYPE_TRAILER => 'Reboque',
                    ])
                    ->default(CiotVehicle::TYPE_AUTOMOTOR)
                    ->required(),
                Forms\Components\Select::make('line')
                    ->label('Linha')
                    ->options([
                        'vehicle_removal' => 'Remoção de veículos',
                        'tank_alcohol' => 'Tanque de álcool',
                    ])
                    ->placeholder('Todas as linhas')
                    ->hintIcon('heroicon-m-information-circle')
                    ->hintIconTooltip('Define a composição sugerida ao gerar CIOT para a linha. Vazio = disponível para todas.'),
                Forms\Components\TextInput::make('description')
                    ->label('Descrição')
                    ->maxLength(150),
                Forms\Components\Toggle::make('is_active')
                    ->label('Ativo')
                    ->default(true),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('plate')
                    ->label('Placa')
                    ->searchable()
                    ->fontFamily('mono')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => $state === CiotVehicle::TYPE_AUTOMOTOR ? 'primary' : 'gray')
                    ->formatStateUsing(fn (string $state): string => $state === CiotVehicle::TYPE_AUTOMOTOR ? 'Automotor' : 'Reboque'),
                Tables\Columns\TextColumn::make('axles')->label('Eixos'),
                Tables\Columns\TextColumn::make('rntrc')->label('RNTRC')->fontFamily('mono')->placeholder('—'),
                Tables\Columns\TextColumn::make('renavam')->label('RENAVAM')->fontFamily('mono')->placeholder('—'),
                Tables\Columns\TextColumn::make('tare_kg')->label('Tara (kg)')->placeholder('—'),
                Tables\Columns\TextColumn::make('capacity_kg')->label('Capacidade (kg)')->placeholder('—'),
                Tables\Columns\TextColumn::make('line')
                    ->label('Linha')
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'gray' : 'primary')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'vehicle_removal' => 'Remoção',
                        'tank_alcohol' => 'Tanque',
                        default => 'Todas',
                    }),
                Tables\Columns\TextColumn::make('description')->label('Descrição')->limit(40)->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')->label('Ativo')->boolean(),
            ])
            ->defaultSort('plate')
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make()->label('Editar'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCiotVehicles::route('/'),
            'create' => Pages\CreateCiotVehicle::route('/create'),
            'edit' => Pages\EditCiotVehicle::route('/{record}/edit'),
        ];
    }
}
