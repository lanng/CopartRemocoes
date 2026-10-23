<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyResource\Pages;
use App\Models\City;
use App\Models\Company;
use App\Services\Ciot\CepLookup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Cadastro';

    protected static ?string $navigationLabel = 'Empresas';

    protected static ?string $modelLabel = 'Empresa';

    protected static ?string $pluralModelLabel = 'Empresas';

    public static function form(Form $form): Form
    {
        return $form->schema(self::companyFormSchema())->columns(1);
    }

    /** @return list<\Filament\Forms\Components\Component> */
    public static function companyFormSchema(bool $includeStatus = true): array
    {
        return [
            Forms\Components\Section::make('Identificação')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Razão social')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('cnpj')
                        ->label('CNPJ (14 dígitos)')
                        ->placeholder('05.257.723/0001-30')
                        ->helperText('Aceita CNPJ com ou sem pontuação.')
                        ->mutateStateForValidationUsing(fn (?string $state): string => Company::normalizeCnpj($state))
                        ->dehydrateStateUsing(fn (?string $state): string => Company::normalizeCnpj($state))
                        ->regex('/^[0-9]{14}$/')
                        ->validationMessages(['regex' => 'Informe um CNPJ com 14 dígitos, com ou sem pontuação.'])
                        ->unique(ignoreRecord: true)
                        ->required(),
                    Forms\Components\TextInput::make('ie')
                        ->label('Inscrição Estadual (IE)')
                        ->helperText('Aceita pontuação. Informe ISENTO para contribuinte isento ou deixe vazio para não contribuinte.')
                        ->mutateStateForValidationUsing(fn (?string $state): ?string => Company::normalizeIe($state))
                        ->dehydrateStateUsing(fn (?string $state): ?string => Company::normalizeIe($state))
                        ->regex('/^(?:[0-9]{1,14}|ISENTO)$/')
                        ->rules(['not_in:0,1'])
                        ->validationMessages([
                            'regex' => 'Informe a IE numérica com até 14 dígitos, ISENTO ou deixe o campo vazio.',
                            'not_in' => 'Informe ISENTO para contribuinte isento ou deixe vazio para não contribuinte; 0 e 1 não são inscrições estaduais.',
                        ]),
                ]),
            Forms\Components\Section::make('Endereço')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('zipcode')
                        ->label('CEP (busca endereço e IBGE)')
                        ->mask('99999-999')
                        ->maxLength(9)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => self::fillFromCep($set, $state))
                        ->dehydrateStateUsing(fn (?string $state): ?string => preg_replace('/\D/', '', (string) $state) ?: null)
                        ->suffixAction(
                            Forms\Components\Actions\Action::make('buscarCep')
                                ->icon('heroicon-m-magnifying-glass')
                                ->label('Buscar CEP')
                                ->color('gray')
                                ->action(function (Forms\Set $set, Forms\Get $get): void {
                                    self::fillFromCep($set, $get('zipcode'));
                                }),
                        )
                        ->required(fn (string $operation): bool => $operation === 'create'),
                    Forms\Components\Select::make('city_picker')
                        ->label('Autocompletar cidade (IBGE)')
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
                        ->afterStateUpdated(function (Forms\Set $set, ?string $state): void {
                            if (blank($state)) {
                                return;
                            }

                            $city = City::query()->where('ibge_code', $state)->first();

                            if ($city !== null) {
                                $set('city', $city->name);
                                $set('state', $city->state);
                                $set('ibge_code', $city->ibge_code);
                            }
                        }),
                    Forms\Components\TextInput::make('street')
                        ->label('Logradouro')
                        ->maxLength(255)
                        ->required(fn (string $operation): bool => $operation === 'create'),
                    Forms\Components\TextInput::make('number')
                        ->label('Número')
                        ->maxLength(20)
                        ->required(fn (string $operation): bool => $operation === 'create'),
                    Forms\Components\TextInput::make('complement')->label('Complemento')->maxLength(100),
                    Forms\Components\TextInput::make('district')
                        ->label('Bairro')
                        ->maxLength(100)
                        ->required(fn (string $operation): bool => $operation === 'create'),
                    Forms\Components\TextInput::make('city')
                        ->label('Cidade')
                        ->required()
                        ->maxLength(100),
                    Forms\Components\TextInput::make('state')
                        ->label('UF')
                        ->length(2)
                        ->required(),
                    Forms\Components\TextInput::make('ibge_code')
                        ->label('Código IBGE (7 dígitos)')
                        ->numeric()
                        ->length(7)
                        ->required(fn (string $operation): bool => $operation === 'create'),
                ]),
            ...($includeStatus ? [Forms\Components\Toggle::make('is_active')->label('Ativo')->default(true)] : []),
        ];
    }

    /**
     * Auto-completa logradouro/bairro/cidade/UF/IBGE a partir do CEP
     * (CepLookup: BrasilAPI → ViaCEP). Falha é silenciosa — o operador
     * preenche manualmente; Número e Complemento nunca são sobrescritos.
     */
    protected static function fillFromCep(Forms\Set $set, ?string $cep): void
    {
        $location = app(CepLookup::class)->lookup((string) $cep);

        if ($location === null) {
            return;
        }

        $set('city', $location['cidade']);
        $set('state', $location['uf']);

        if (filled($location['ibge'])) {
            $set('ibge_code', $location['ibge']);
        }

        if (filled($location['logradouro'])) {
            $set('street', $location['logradouro']);
        }

        if (filled($location['bairro'])) {
            $set('district', $location['bairro']);
        }
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
            'index' => Pages\ListCompanies::route('/'),
            'create' => Pages\CreateCompany::route('/create'),
            'edit' => Pages\EditCompany::route('/{record}/edit'),
        ];
    }
}
