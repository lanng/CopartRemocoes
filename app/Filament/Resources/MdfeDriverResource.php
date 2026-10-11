<?php

namespace App\Filament\Resources;

use App\Enums\CiotLineEnum;
use App\Filament\Resources\MdfeDriverResource\Pages;
use App\Models\MdfeDriver;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MdfeDriverResource extends Resource
{
    protected static ?string $model = MdfeDriver::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Cadastro';

    protected static ?string $navigationLabel = 'Motoristas MDF-e';

    protected static ?string $modelLabel = 'Motorista MDF-e';

    protected static ?string $pluralModelLabel = 'Motoristas MDF-e';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identificação fiscal')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome completo')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('cpf')
                            ->label('CPF (11 dígitos)')
                            ->numeric()
                            ->length(11)
                            ->unique(ignoreRecord: true)
                            ->required(),
                        Forms\Components\Select::make('line')
                            ->label('Tipo de emissão')
                            ->options(collect(CiotLineEnum::cases())->mapWithKeys(
                                fn (CiotLineEnum $line): array => [$line->value => $line->label()],
                            ))
                            ->required()
                            ->live(),
                        Forms\Components\Toggle::make('is_default')
                            ->label('Motorista fixo da remoção de veículos')
                            ->default(false)
                            ->visible(fn (Forms\Get $get): bool => $get('line') === CiotLineEnum::VehicleRemoval->value)
                            ->dehydrated(fn (Forms\Get $get): bool => $get('line') === CiotLineEnum::VehicleRemoval->value),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Ativo')
                            ->default(true)
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),
                Tables\Columns\TextColumn::make('cpf')
                    ->label('CPF')
                    ->formatStateUsing(fn (string $state): string => '***.***.'.substr($state, 6, 3).'-'.substr($state, 9, 2)),
                Tables\Columns\TextColumn::make('line')
                    ->label('Tipo de emissão')
                    ->formatStateUsing(fn (string $state): string => CiotLineEnum::from($state)->label())
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_default')
                    ->label('Fixo na remoção')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('line')
                    ->label('Tipo de emissão')
                    ->options(collect(CiotLineEnum::cases())->mapWithKeys(
                        fn (CiotLineEnum $line): array => [$line->value => $line->label()],
                    )),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Ativo'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMdfeDrivers::route('/'),
            'create' => Pages\CreateMdfeDriver::route('/create'),
            'edit' => Pages\EditMdfeDriver::route('/{record}/edit'),
        ];
    }
}
