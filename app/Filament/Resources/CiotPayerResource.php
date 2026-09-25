<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CiotPayerResource\Pages;
use App\Models\CiotPayer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CiotPayerResource extends Resource
{
    protected static ?string $model = CiotPayer::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'CIOT';

    protected static ?string $navigationLabel = 'Pagantes';

    protected static ?string $modelLabel = 'Pagante';

    protected static ?string $pluralModelLabel = 'Pagantes';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identificação')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('cnpj')
                            ->label('CNPJ (14 dígitos)')
                            ->numeric()
                            ->length(14)
                            ->unique(ignoreRecord: true)
                            ->required(),
                    ]),
                Forms\Components\Section::make('Endereço')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('street')->label('Logradouro')->maxLength(255),
                        Forms\Components\TextInput::make('number')->label('Número')->maxLength(20),
                        Forms\Components\TextInput::make('complement')->label('Complemento')->maxLength(100),
                        Forms\Components\TextInput::make('district')->label('Bairro')->maxLength(100),
                        Forms\Components\TextInput::make('city')
                            ->label('Cidade')
                            ->required()
                            ->maxLength(100),
                        Forms\Components\TextInput::make('state')
                            ->label('UF')
                            ->length(2)
                            ->required(),
                        Forms\Components\TextInput::make('zipcode')
                            ->label('CEP (8 dígitos)')
                            ->numeric()
                            ->length(8),
                        Forms\Components\TextInput::make('ibge_code')
                            ->label('Código IBGE (7 dígitos)')
                            ->numeric()
                            ->length(7),
                    ]),
                Forms\Components\Toggle::make('is_active')
                    ->label('Ativo')
                    ->default(true),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('cnpj')
                    ->label('CNPJ')
                    ->formatStateUsing(fn (string $state): string => sprintf(
                        '%s.%s.%s/%s-%s',
                        substr($state, 0, 2),
                        substr($state, 2, 3),
                        substr($state, 5, 3),
                        substr($state, 8, 4),
                        substr($state, 12, 2),
                    ))
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('city')->label('Cidade'),
                Tables\Columns\TextColumn::make('state')->label('UF'),
                Tables\Columns\IconColumn::make('is_active')->label('Ativo')->boolean(),
            ])
            ->defaultSort('name')
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make()->label('Editar'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCiotPayers::route('/'),
            'create' => Pages\CreateCiotPayer::route('/create'),
            'edit' => Pages\EditCiotPayer::route('/{record}/edit'),
        ];
    }
}
