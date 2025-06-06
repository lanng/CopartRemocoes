<?php
namespace App\Filament\Resources;

use App\Enums\RegisterStatusEnum;
use App\Filament\Resources\RegisterResource\Pages\CreateRegister;
use App\Filament\Resources\RegisterResource\Pages\EditRegister;
use App\Filament\Resources\RegisterResource\Pages\ListRegisters;
use App\Filament\Resources\RegisterResource\Pages\ViewRegister;
use App\Models\Register;
use App\Services\PdfExtractorService;
use Exception;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Leandrocfe\FilamentPtbrFormFields\Money;

class RegisterResource extends Resource
{
    protected static ?string $model = Register::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Registros';

    protected static ?string $modelLabel = 'Registro Copart';

    protected static ?string $pluralModelLabel = 'Registros Copart';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Dados do veículo')->schema([
                    TextInput::make('vehicle_model')
                        ->label('Veículo')
                        ->required()
                        ->maxLength(30),
                    TextInput::make('vehicle_plate')
                        ->label('Placa do veículo')
                        ->required()
                        ->maxLength(7)
                        ->unique(ignoreRecord: true)
                        ->validationMessages([
                            'unique' => 'Placa já cadastrada',
                        ]),
                    TextInput::make('origin_city')
                        ->label('Cidade origem')
                        ->required()
                        ->maxLength(50),
                    TextInput::make('destination_city')
                        ->label('Cidade destino')
                        ->required()
                        ->maxLength(50),
                    TextInput::make('vehicle_id')
                        ->label('Código do veículo')
                        ->required()
                        ->numeric(),
                ]),

                Section::make('Datas')->schema([
                    DatePicker::make('deadline_withdraw')
                        ->label('Data limite para recolha')
                        ->validationAttribute('Data limite para recolha')
                        ->required()
                        ->native(false)
                        ->live()
                        ->beforeOrEqual(fn(Get $get) => $get('deadline_delivery') ?? null),
                    DatePicker::make('deadline_delivery')
                        ->label('Data limite entrega')
                        ->validationAttribute('Data limite entrega')
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterOrEqual('deadline_withdraw'),
                    DatePicker::make('collected_date')
                        ->label('Data da recolha')
                        ->validationAttribute('Data da recolha')
                        ->native(false)
                        ->live()
                        ->afterOrEqual('deadline_withdraw')
                        ->beforeOrEqual('deadline_delivery'),
                ]),

                Section::make('Detalhes Financeiros e Status')->schema([
                    TextInput::make('driver')
                        ->label('Motorista')
                        ->maxLength(30),
                    TextInput::make('driver_plate')
                        ->label('Placa guincho')
                        ->maxLength(7),
                    Money::make('value')
                        ->label('Valor')
                        ->required(),
                    Select::make('status')
                        ->label('Situação')
                        ->options(RegisterStatusEnum::optionsWithLabels())
                        ->enum(RegisterStatusEnum::class)
                        ->default(RegisterStatusEnum::PENDING)
                        ->required(),
                ]),

                Section::make('Documentos e Observações')->schema([
                    FileUpload::make('pdf_path')
                        ->label('PDF')
                        ->disk('s3')
                        ->directory(config('awss3.s3_bucket'))
                        ->visibility('public')
                        ->downloadable()
                        ->openable()
                        ->acceptedFileTypes(['application/pdf'])
                        ->required()
                        ->preserveFilenames()
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            if (blank($state)) {
                                $set('vehicle_model', '');
                                $set('vehicle_plate', '');
                                $set('origin_city', '');
                                $set('destination_city', '');
                                $set('vehicle_id', '');
                                return;
                            }

                            try {
                                $extractor     = app(PdfExtractorService::class);
                                $extractedData = $extractor->extractData($state->getRealPath());

                                if (isset($extractedData['error'])) {
                                    Notification::make()
                                        ->danger()
                                        ->title('Erro na Extração')
                                        ->body($extractedData['error'])
                                        ->send();
                                    return;
                                }

                                $set('vehicle_model', $extractedData['vehicle_model'] ?? $get('vehicle_model'));
                                $set('vehicle_plate', $extractedData['vehicle_plate'] ?? $get('vehicle_plate'));
                                $set('origin_city', $extractedData['origin_city'] ?? $get('origin_city'));
                                $set('destination_city', $extractedData['destination_city'] ?? $get('destination_city'));
                                $set('vehicle_id', $extractedData['vehicle_id'] ?? $get('vehicle_id'));

                                if (! empty($extractedData['deadline_withdraw'])) {
                                    try {
                                        $date = Carbon::createFromFormat('d/m/Y', $extractedData['deadline_withdraw'])->format('Y-m-d');
                                        $set('deadline_withdraw', $date);
                                    } catch (Exception $e) {
                                        Log::warning("Could not parse deadline_withdraw from PDF: " . $extractedData['deadline_withdraw']);
                                    }
                                }
                                if (! empty($extractedData['deadline_delivery'])) {
                                    try {
                                        $date = Carbon::createFromFormat('d/m/Y', $extractedData['deadline_delivery'])->format('Y-m-d');
                                        $set('deadline_delivery', $date);
                                    } catch (Exception $e) {
                                        Log::warning("Could not parse deadline_delivery from PDF: " . $extractedData['deadline_delivery']);
                                    }
                                }

                                if (! empty($extractedData['origin_phones'])) {
                                    $phoneString = implode(' / ', $extractedData['origin_phones']);
                                    $notes       = $get('notes');
                                    $set('notes', ($notes ? $notes . "\n" : '') . "Telefones Origem: " . $phoneString);
                                }

                                Notification::make()
                                    ->success()
                                    ->title('Dados Extraídos')
                                    ->body('Campos preenchidos com base no PDF. Por favor, revise e complete as informações.')
                                    ->send();

                            } catch (Exception $e) {
                                Notification::make()
                                    ->danger()
                                    ->title('Erro Inesperado')
                                    ->body('Ocorreu um erro ao tentar extrair dados do PDF: ' . $e->getMessage())
                                    ->send();
                                Log::error("Unexpected error during PDF extraction service call: " . $e->getMessage(), ['state' => $state]);
                            }
                        })
                        ->helperText('Faça upload do PDF para tentar preencher os campos automaticamente.'),
                    Textarea::make('notes')
                        ->label('Observações')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(25)
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('vehicle_model')
                            ->weight('bold')
                            ->searchable(),
                        TextColumn::make('vehicle_plate')
                            ->badge()
                            ->searchable(),
                        TextColumn::make('origin_city')
                            ->icon('heroicon-o-arrow-up-circle')
                            ->searchable(),
                        TextColumn::make('destination_city')
                            ->icon('heroicon-o-arrow-down-circle')
                            ->searchable(),
                    ]),

                    Stack::make([
                        TextColumn::make('deadline_withdraw')
                            ->label('Data limite recolha') //label just for orderBy option
                            ->icon('heroicon-o-exclamation-triangle')
                            ->color(fn(Register $record) => $record->deadline_withdraw?->isPast() && ! $record->isCollected() && ! $record->isCancelled() ? 'danger' : 'gray')
                            ->tooltip(fn(Register $record) => $record->deadline_withdraw?->isPast() && ! $record->isCollected() && ! $record->isCancelled() ? 'Remoção Atrasada!' : null)
                            ->date('d/m/Y')
                            ->sortable(),
                        TextColumn::make('deadline_delivery')
                            ->label('Data limite entrega') //label just for orderBy option
                            ->icon('heroicon-o-calendar-days')
                            ->color(fn(Register $record) => $record->deadline_delivery?->isPast() && ! $record->isDelivered() && ! $record->isCancelled() ? 'warning' : 'gray')
                            ->tooltip(fn(Register $record) => $record->deadline_delivery?->isPast() && ! $record->isDelivered() && ! $record->isCancelled() ? 'Entrega Atrasada!' : null)
                            ->date('d/m/Y')
                            ->sortable(),
                        TextColumn::make('status')
                            ->label('Situação') //label just for orderBy option
                            ->badge()
                            ->color(fn(RegisterStatusEnum $state): string => $state->color())
                            ->formatStateUsing(fn(RegisterStatusEnum $state): string => $state->localizedLabel())
                            ->sortable()
                            ->searchable(),
                    ]),

                    Stack::make([
                        TextColumn::make('pdf_path')
                            ->icon('heroicon-o-document-text')
                            ->formatStateUsing(fn($state) => 'Ver PDF')
                            ->url(fn($record): ?string => $record->pdf_path ? Storage::disk('s3')->url($record->pdf_path) : null)
                            ->openUrlInNewTab(),
                    ]),
                ]),

                Panel::make([
                    Stack::make([
                        TextColumn::make('driver')
                            ->formatStateUsing(fn($state) => '<strong> Motorista: </strong>' . $state ?: '-')
                            ->html()
                            ->icon('heroicon-o-user')
                            ->searchable(),
                        TextColumn::make('driver_plate')
                            ->formatStateUsing(fn($state) => '<strong> Placa guincho: </strong>' . $state ?: '-')
                            ->html()
                            ->icon('heroicon-o-truck')
                            ->searchable(),
                        TextColumn::make('collected_date')
                            ->date('d/m/Y')
                            ->placeholder('-')
                            ->icon('heroicon-o-check-circle'),
                        TextColumn::make('vehicle_id')
                            ->icon('heroicon-o-identification')
                            ->searchable(),
                        TextColumn::make('value')
                            ->formatStateUsing(fn($state) => '<strong> Valor: R$ </strong>' . str_replace('.', ',', $state))
                            ->html(),
                        TextColumn::make('notes')
                            ->formatStateUsing(fn($state) => '<strong> Obs.: </strong>' . str_replace('.', ',', $state))
                            ->html(),
                    ]),
                ])->collapsed(),
            ])
            ->emptyStateHeading('Não há registros!')
            ->emptyStateDescription('Nenhum registro encontrado. Tente ajustar sua busca ou crie um novo registro.')
            ->filters([
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options(RegisterStatusEnum::optionsWithLabels()),
            ])
            ->actions([
                EditAction::make()->iconButton(),
                Action::make('updateStatusSingle')
                    ->label('Atual. Situação')
                    ->icon('heroicon-o-arrow-path')
                    ->form(self::getUpdateStatusFormSchema())
                    ->action(function (array $data, Register $record): void {
                        self::updateRegisterStatus($record, $data);
                    })
                    ->color('primary')
                    ->modalWidth('lg'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Apagar Selecionados'),
                    BulkAction::make('updateStatusMulti')
                        ->label('Atualizar Situação (em massa)')
                        ->icon('heroicon-o-pencil-square')
                        ->form(self::getUpdateStatusFormSchema())
                        ->action(function (array $data, Collection $records): void {
                            $records->each(fn(Register $record) => self::updateRegisterStatus($record, $data));
                        })
                        ->color('primary')
                        ->modalWidth('lg')
                        ->deselectRecordsAfterCompletion(),
                ])->label('Ações em massa'),
            ])->modifyQueryUsing(function (Builder $query) {
            $query->orderByRaw("
                    CASE status
                        WHEN 'pending' THEN 1
                        WHEN 'pending daily rates' THEN 2
                        WHEN 'available' THEN 3
                        WHEN 'collected' THEN 4
                        WHEN 'delivered' THEN 5
                        WHEN 'invoiced' THEN 6
                        ELSE 7
                    END ASC
                ");
        })
            ->recordUrl(fn(Register $record): string => static::getUrl('view', ['record' => $record]));
    }

    protected static function getUpdateStatusFormSchema(): array
    {
        return [
            Select::make('status')
                ->label('Situação')
                ->options(RegisterStatusEnum::optionsWithLabels())
                ->enum(RegisterStatusEnum::class)
                ->required()
                ->live(),
            DatePicker::make('collected_date')
                ->label('Data da coleta')
                ->native(false)
                ->visible(fn(Get $get) => $get('status') === RegisterStatusEnum::COLLECTED->value)
                ->requiredIf('status', RegisterStatusEnum::COLLECTED->value),
            TextInput::make('driver')
                ->label('Motorista (opcional)')
                ->visible(fn(Get $get) => $get('status') === RegisterStatusEnum::COLLECTED->value)
                ->requiredIf('status', RegisterStatusEnum::COLLECTED->value)
                ->maxLength(30),
            TextInput::make('driver_plate')
                ->label('Placa guincho (opcional)')
                ->visible(fn(Get $get) => $get('status') === RegisterStatusEnum::COLLECTED->value)
                ->requiredIf('status', RegisterStatusEnum::COLLECTED->value)
                ->maxLength(7),
        ];
    }

    protected static function updateRegisterStatus(Register $record, array $data): void
    {
        $updateData = ['status' => $data['status']];

        if ($data['status'] === RegisterStatusEnum::COLLECTED->value) {
            if (isset($data['collected_date'])) {
                $updateData['collected_date'] = $data['collected_date'];
            }
            if (isset($data['driver'])) {
                $updateData['driver'] = $data['driver'];
            }
            if (isset($data['driver_plate'])) {
                $updateData['driver_plate'] = $data['driver_plate'];
            }
        }

        $record->update($updateData);
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
            'index'  => ListRegisters::route('/'),
            'create' => CreateRegister::route('/create'),
            'view'   => ViewRegister::route('/{record}'),
            'edit'   => EditRegister::route('/{record}/edit'),
        ];
    }
}
