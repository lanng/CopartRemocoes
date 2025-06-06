<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use App\Filament\Resources\ActivityLogResource\RelationManagers;
use App\Models\Register;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 99;

    public static function canViewAny(): bool
    {
        if (Auth::check()) {
            return Auth::user()->email === config('authuser.user');
        }
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('log_name')
                    ->label('Log'),
                Forms\Components\Textarea::make('description')
                    ->label('Descrição')
                    ->columnSpanFull(),
                Forms\Components\MorphToSelect::make('subject')
                    ->label('Veículo')
                    ->types([
                        Forms\Components\MorphToSelect\Type::make(Register::class)->titleAttribute('vehicle_plate'),
                    ]),
                Forms\Components\MorphToSelect::make('causer')
                    ->label('Usuário')
                    ->types([
                        Forms\Components\MorphToSelect\Type::make(User::class)->titleAttribute('name'),
                    ]),
                Forms\Components\KeyValue::make('properties.old')
                    ->label('Valores Antigos')
                    ->disabled(),
                Forms\Components\KeyValue::make('properties.attributes')
                    ->label('Valores Novos')
                    ->disabled(),
                Forms\Components\DateTimePicker::make('created_at')
                    ->label('Data e hora')
                    ->timezone('America/Sao_Paulo')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data e hora')
                    ->dateTime('d/m/y H:i')
                    ->timezone('America/Sao_Paulo')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('log_name')
                    ->label('Log')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Ação')
                    ->limit(50)
                    ->tooltip(fn (Activity $record): string => $record->description)
                    ->searchable(),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label('User')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Model')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
//                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
//                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
//            'create' => Pages\CreateActivityLog::route('/create'),
            'view' => Pages\ViewActivityLog::route('/{record}'),
//            'edit' => Pages\EditActivityLog::route('/{record}/edit'),
        ];
    }

    public static function canCreate():bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }
}
