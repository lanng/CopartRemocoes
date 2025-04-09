<?php

namespace App\Filament\Resources;

use App\Enums\RegisterStatusEnum;
use App\Filament\Resources\RegisterResource\Pages;
use App\Filament\Resources\RegisterResource\RelationManagers;
use App\Models\Register;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Unique;

class RegisterResource extends Resource
{
    protected static ?string $model = Register::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Registros';

    protected static ?string $modelLabel = 'Registros Copart';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Dados do veículo')->schema([
                    Forms\Components\TextInput::make('vehicle_model')
                        ->required()
                        ->label('Veículo')
                        ->maxLength(30),
                    Forms\Components\TextInput::make('vehicle_plate')
                        ->required()
                        ->label('Placa do veículo')
                        ->maxLength(7)
                        ->unique()
                        ->validationMessages([
                            'unique' => 'Placa já cadastrada',
                        ]),
                    Forms\Components\TextInput::make('origin_city')
                        ->required()
                        ->label('Cidade origem')
                        ->maxLength(50),
                    Forms\Components\TextInput::make('destination_city')
                        ->required()
                        ->label('Cidade destino')
                        ->maxLength(50),
                    Forms\Components\TextInput::make('vehicle_id')
                        ->label('Código do veículo')
                        ->required()
                        ->numeric()
                        ->maxLength(10),
                ]),
                Forms\Components\Section::make('Datas')->schema([
                    Forms\Components\DatePicker::make('deadline_withdraw')
                        ->label('Data limite para recolha')
                        ->required(),
                    Forms\Components\DatePicker::make('deadline_delivery')
                        ->label('Data limite entrega')
                        ->required(),
                    Forms\Components\DatePicker::make('collected_date')
                        ->label('Data da recolha'),
                ]),
                Forms\Components\TextInput::make('driver')
                    ->label('Motorista')
                    ->maxLength(30),
                Forms\Components\TextInput::make('driver_plate')
                    ->label('Placa guincho')
                    ->maxLength(7),
                Forms\Components\TextInput::make('value')
                    ->required()
                    ->label('Valor')
                    ->numeric(),
                Forms\Components\Select::make('status')
                    ->required()
                    ->label('Situação')
                    ->options(RegisterStatusEnum::optionsWithLabels())
                    ->enum(RegisterStatusEnum::class)
                    ->default(RegisterStatusEnum::PENDING),
                Forms\Components\FileUpload::make('pdf_path')
                    ->label('PDF')
                    ->disk('s3')
                    ->directory('registers')
                    ->visibility('public')
                    ->downloadable()
                    ->previewable()
                    ->acceptedFileTypes(['application/pdf'])
                    ->required()
                    ->preserveFilenames()
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\TextColumn::make('vehicle_model')
                        ->searchable(),
                    Tables\Columns\TextColumn::make('vehicle_plate')
                        ->label('Placa')
                        ->searchable(),
                    Tables\Columns\TextColumn::make('origin_city')
                        ->label('Origem')
                        ->searchable(),
                    Tables\Columns\TextColumn::make('deadline_withdraw')
                        ->label('Data limite remoção')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->color('danger')
                        ->date()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('pdf_path')
                        ->label('PDF')
                        ->formatStateUsing(fn($state) => 'Ver PDF')
                        ->url(fn($record) => Storage::disk('s3')->url($record->pdf_path))
                        ->openUrlInNewTab(),
                    Tables\Columns\TextColumn::make('status')
                        ->label('Situação')
                        ->sortable()
                        ->badge()
                        ->color(fn(RegisterStatusEnum $state): string => $state->color())
                        ->formatStateUsing(fn(RegisterStatusEnum $state): string => $state->localizedLabel())
                ]),
                Tables\Columns\Layout\Panel::make([
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('destination_city')
                            ->formatStateUsing(fn($state) => '<strong>Patio destino: </strong>' . $state)
                            ->html()
                            ->searchable(),
                        Tables\Columns\TextColumn::make('deadline_delivery')
                            ->formatStateUsing(fn($state) => '<strong>Data limite entrega: </strong>' . Carbon::parse($state)->format('d/m/Y'))
                            ->html()
                            ->label('Data limite entrega')
                            ->sortable(),
                        Tables\Columns\TextColumn::make('collected_date')
                            ->formatStateUsing(fn($state) => '<strong>Data coletado: </strong>' . Carbon::parse($state)->format('d/m/Y'))
                            ->html()
                            ->toggleable(isToggledHiddenByDefault: true)
                            ->sortable(),
                        Tables\Columns\TextColumn::make('driver')
                            ->formatStateUsing(fn($state) => '<strong> Motorista: </strong>' . $state)
                            ->html()
                            ->toggleable(isToggledHiddenByDefault: true)
                            ->searchable(),
                        Tables\Columns\TextColumn::make('driver_plate')
                            ->formatStateUsing(fn($state) => '<strong> Placa motorista: </strong>' . $state)
                            ->html()
                            ->toggleable(isToggledHiddenByDefault: true)
                            ->searchable(),
                        Tables\Columns\TextColumn::make('vehicle_id')
                            ->formatStateUsing(fn($state) => '<strong> Cod. Veículo: </strong>' . $state)
                            ->html()
                            ->toggleable(isToggledHiddenByDefault: true)
                            ->searchable(),
                        Tables\Columns\TextColumn::make('value')
                            ->formatStateUsing(fn($state) => '<strong> Valor: R$ </strong>' . str_replace('.', ',', $state))
                            ->html()
                            ->toggleable(isToggledHiddenByDefault: true)
                            ->sortable(),
                    ])
                ])->collapsed(),
            ])
            ->emptyStateHeading('Não há registros!')
            ->emptyStateDescription('Busca sem resultado, se persistir entre em contato com o administrador do sistema.')
            ->filters([
                SelectFilter::make('status')->label('Situação')
                    ->options([
                        'pending' => 'Pendentes',
                        'collected' => 'Coletados',
                        'delivered' => 'Entregues',
                        'invoiced' => 'Em nota fiscal',
                        'cancelled' => 'Cancelados',
                        'available' => 'Liberado',
                        'pending daily rates' => 'Diárias pendentes'
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Apagar Registros'),
                    Tables\Actions\BulkAction::make('updateStatus')
                        ->label('Atualizar situação')
                        ->icon('heroicon-o-pencil-square')
                        ->form([
                            Forms\Components\Select::make('status')
                                ->label('Situação')
                                ->options(RegisterStatusEnum::optionsWithLabels())
                                ->required()
                                ->reactive(),
                            Forms\Components\DatePicker::make('collected_date')
                                ->label('Data da coleta')
                                ->requiredIf('status', RegisterStatusEnum::COLLECTED->value)
                                ->visible(fn ($get) => $get('status') === RegisterStatusEnum::COLLECTED->value)
                                ->required(),
                            Forms\Components\TextInput::make('driver')
                                ->label('Motorista (se houver)')
                                ->requiredIf('status', RegisterStatusEnum::COLLECTED->value)
                                ->visible(fn ($get) => $get('status') === RegisterStatusEnum::COLLECTED->value),
                            Forms\Components\TextInput::make('driver_plate')
                                ->label('Placa guincho (se houver)')
                                ->requiredIf('status', RegisterStatusEnum::COLLECTED->value)
                                ->visible(fn ($get) => $get('status') === RegisterStatusEnum::COLLECTED->value)
                        ])->action(function (array $data, Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => $data['status'],
                                    'collected_date' => $data['collected_date'] ?? $record->collected_date,
                                    'driver' => $data['driver'] ?? $record->driver,
                                    'driver_plate' => $data['driver_plate'] ?? $record->driver_plate
                                ]);
                            }
                        })->color('primary')
                ])->label('Opções'),
            ])->recordUrl(fn(Register $record): string => static::getUrl('view', ['record' => $record]));
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
            'index' => Pages\ListRegisters::route('/'),
            'create' => Pages\CreateRegister::route('/create'),
            'view' => Pages\ViewRegister::route('/{record}'),
            'edit' => Pages\EditRegister::route('/{record}/edit'),
        ];
    }
}
