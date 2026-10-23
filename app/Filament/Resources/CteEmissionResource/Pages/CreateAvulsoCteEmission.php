<?php

namespace App\Filament\Resources\CteEmissionResource\Pages;

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CteEmissionResource;
use App\Models\City;
use App\Models\Company;
use App\Services\Cte\CreateGeneralCargoCteDocument;
use App\Services\Cte\ExactDecimal;
use App\Services\Cte\ExtractNfeReference;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Leandrocfe\FilamentPtbrFormFields\Money;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CreateAvulsoCteEmission extends CreateRecord
{
    /** @var list<array<string, mixed>> */
    public array $nfeImportPreview = [];

    /** @var list<string> */
    public array $nfeImportWarnings = [];

    /** @var array<string, mixed> */
    public array $nfeImportSuggestions = [];

    /** @var array<string, bool> */
    public array $manualCompanySelections = [];

    private bool $applyingNfeSuggestions = false;

    public ?string $nfeImportError = null;

    protected static string $resource = CteEmissionResource::class;

    protected static ?string $title = 'Novo CT-e avulso';

    public function form(Form $form): Form
    {
        $maximumFileSizeKilobytes = (int) ceil(config('dfe.nfe_xml_max_file_size_bytes') / 1024);
        $maximumTotalFileSizeMegabytes = number_format(config('dfe.nfe_xml_max_total_size_bytes') / 1024 / 1024, 1, ',', '.');
        $maximumReferences = (int) config('dfe.nfe_xml_max_files_per_import');

        return $form
            ->schema([
                Section::make('Documento originário')
                    ->schema([
                        Select::make('origin_document_mode')
                            ->label('Tipo de documento originário')
                            ->options([
                                'nfe' => 'NF-e',
                                'other' => 'Outros documentos / Carta de remoção',
                            ])
                            ->default('nfe')
                            ->live()
                            ->required()
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                if ($state === 'other') {
                                    $set('nfe_keys', '');
                                    $set('nfe_xmls', []);
                                    $this->applyNfeImport([], $get, $set);

                                    return;
                                }

                                $set('carta_number', '');
                                $set('carta_issued_on', null);
                                $set('carta_attachment', null);
                            }),
                        Section::make('Referências de NF-e')
                            ->description('Informe uma ou mais chaves modelo 55 ou envie os XMLs. A chave digitada terá formato, modelo e dígito verificador validados, mas sua situação não será consultada.')
                            ->schema([
                                Textarea::make('nfe_keys')
                                    ->label('Chaves de acesso')
                                    ->rows(3)
                                    ->helperText('Uma chave de 44 dígitos por linha. Pode combinar chaves e arquivos XML.'),
                                FileUpload::make('nfe_xmls')
                                    ->label('XMLs de NF-e')
                                    ->multiple()
                                    ->live()
                                    ->storeFiles(false)
                                    ->preserveFilenames()
                                    ->acceptedFileTypes(['application/xml', 'text/xml', 'application/octet-stream'])
                                    ->rules(['extensions:xml'])
                                    ->maxSize($maximumFileSizeKilobytes)
                                    ->maxFiles($maximumReferences)
                                    ->helperText("A leitura sugere partes fiscais, produtos, NCM, total, peso e volumes. Limite total: {$maximumTotalFileSizeMegabytes} MB. Confira os campos; rota física, tomador, CFOP e frete são informados separadamente.")
                                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                        $this->applyNfeImport($state, $get, $set);
                                    }),
                                Placeholder::make('nfe_import_preview')
                                    ->label('Dados lidos dos XMLs — confira antes de salvar')
                                    ->content(fn (): HtmlString => $this->nfeImportPreviewContent())
                                    ->visible(fn (): bool => $this->nfeImportPreview !== [] || $this->nfeImportWarnings !== [] || filled($this->nfeImportError))
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->visible(fn (Get $get): bool => (string) $get('origin_document_mode') === 'nfe'),
                        Section::make('Carta de remoção')
                            ->description('Use esta opção para operações de remoção/devolução respaldadas por carta. Ela não substitui NF-e quando a legislação exigir NF-e.')
                            ->columns(2)
                            ->schema([
                                TextInput::make('carta_number')
                                    ->label('Número da carta (se houver)')
                                    ->maxLength(20),
                                DatePicker::make('carta_issued_on')
                                    ->label('Data de emissão da carta (se houver)')
                                    ->native(false),
                                FileUpload::make('carta_attachment')
                                    ->label('Anexo da carta (opcional)')
                                    ->storeFiles(false)
                                    ->preserveFilenames()
                                    ->previewable(false)
                                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                                    ->rules(['mimes:pdf,jpg,jpeg,png', 'max:10240'])
                                    ->maxSize(10240)
                                    ->helperText('PDF, JPEG ou PNG de até 10 MB. O anexo fica no arquivo fiscal privado e não é incorporado ao XML.'),
                            ])
                            ->visible(fn (Get $get): bool => (string) $get('origin_document_mode') === 'other'),
                    ])
                    ->columns(1),
                Section::make('Operação e rota')
                    ->columns(2)
                    ->schema([
                        ...$this->citySelect('origin', 'Município físico de início', 'name'),
                        ...$this->citySelect('destination', 'Município físico de término', 'name'),
                        Placeholder::make('origin_tax_notice')
                            ->label('Conferência fiscal da UF de início')
                            ->content('Confirme com o escritório o ICMS da UF de início. Quando houver guia devida, recolha antes da viagem. O sistema não calcula nem emite essa guia.')
                            ->visible(fn (Get $get): bool => filled($get('origin.uf'))
                                && strtoupper((string) $get('origin.uf')) !== 'SP')
                            ->columnSpanFull(),
                        TextInput::make('cfop')
                            ->label('CFOP')
                            ->maxLength(4)
                            ->live()
                            ->helperText(fn (Get $get): string => $this->cfopHelper($get)),
                        Hidden::make('cfop_suggestion'),
                        Placeholder::make('cfop_route_warning')
                            ->label('Revisar CFOP')
                            ->content('A rota mudou e o CFOP informado não corresponde à sugestão atual. Confirme o código antes de salvar.')
                            ->visible(fn (Get $get): bool => filled($get('cfop'))
                                && filled($get('cfop_suggestion'))
                                && (string) $get('cfop') !== (string) $get('cfop_suggestion'))
                            ->columnSpanFull(),
                        TextInput::make('nat_op')
                            ->label('Natureza da operação')
                            ->default('PREST SERV TRANSPORTE')
                            ->required()
                            ->maxLength(60),
                    ]),
                Section::make('Remetente')
                    ->columns(2)
                    ->schema($this->companyFields('rem_company_id', 'Empresa remetente')),
                Section::make('Destinatário')
                    ->columns(2)
                    ->schema($this->companyFields('dest_company_id', 'Empresa destinatária')),
                Section::make('Tomador do serviço')
                    ->columns(2)
                    ->schema([
                        Select::make('taker_code')
                            ->label('Tomador do serviço')
                            ->options([
                                '0' => 'Remetente',
                                '3' => 'Destinatário',
                                '4' => 'Outro (informar cadastro)',
                            ])
                            ->required()
                            ->live(),
                        Select::make('ind_ietoma')
                            ->label('Situação do tomador perante o ICMS')
                            ->options([
                                '1' => 'Contribuinte ICMS',
                                '2' => 'Contribuinte isento de inscrição',
                                '9' => 'Não contribuinte',
                            ])
                            ->required()
                            ->helperText('Escolha conforme a situação do tomador nesta prestação; não é deduzida pela presença de inscrição estadual na NF-e.'),
                        Select::make('taker_is_transport_company')
                            ->label('O tomador é uma transportadora?')
                            ->options(['0' => 'Não', '1' => 'Sim'])
                            ->required()
                            ->helperText('Este fluxo não transmite operações de subcontratação ou redespacho.'),
                        Section::make('Tomador “outro”')
                            ->columns(2)
                            ->schema($this->partyFields('taker'))
                            ->visible(fn (Get $get): bool => (string) $get('taker_code') === '4')
                            ->columnSpanFull(),
                    ]),
                Section::make('Carga')
                    ->columns(2)
                    ->schema([
                        Money::make('cargo.value')
                            ->label('Valor total da carga')
                            ->required(),
                        TextInput::make('cargo.predominant_product')
                            ->label('Produto predominante')
                            ->required()
                            ->maxLength(60),
                        TextInput::make('cargo.other_category')
                            ->label('Outras características da carga')
                            ->maxLength(30),
                        Repeater::make('cargo.measurements')
                            ->label('Medidas da carga')
                            ->helperText('Informe PESO ESTIMADO em kg (unidade 01) ou VEICULOS/UNIDADES transportados (unidade 03). Peso estimado não é peso aferido.')
                            ->schema([
                                Select::make('unit')
                                    ->label('Unidade')
                                    ->options([
                                        '00' => 'm³',
                                        '01' => 'kg',
                                        '02' => 'toneladas',
                                        '03' => 'unidades',
                                        '04' => 'litros',
                                        '05' => 'MMBTU',
                                    ])
                                    ->required(),
                                TextInput::make('measure')
                                    ->label('Descrição da medida')
                                    ->placeholder('PESO ESTIMADO, VEICULOS ou UNIDADES')
                                    ->required()
                                    ->maxLength(20),
                                TextInput::make('quantity')
                                    ->label('Quantidade')
                                    ->numeric()
                                    ->required(),
                            ])
                            ->minItems(1)
                            ->columns(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Frete')
                    ->columns(2)
                    ->schema([
                        Money::make('freight.total')->label('Valor total da prestação')->required(),
                        Money::make('freight.receivable')->label('Valor a receber')->required(),
                        Repeater::make('freight.components')
                            ->label('Componentes do frete')
                            ->schema([
                                TextInput::make('name')->label('Componente')->required()->maxLength(15),
                                Money::make('value')->label('Valor')->required(),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                        Placeholder::make('draft_notice')
                            ->label('Próximo passo')
                            ->content('Salvar cria somente um rascunho. Revise as partes, a carga e o frete na página seguinte; a emissão é iniciada separadamente.')
                            ->columnSpanFull(),
                    ]),
            ])
            ->columns(1);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Salvar rascunho');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Rascunho de CT-e avulso criado.';
    }

    protected function getRedirectUrl(): string
    {
        return CteEmissionResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $data['nfe_xmls'] = $this->uploadedNfeXmls((array) ($data['nfe_xmls'] ?? []));
            $data['carta_attachment'] = $this->uploadedCartaAttachment($data['carta_attachment'] ?? null);

            return app(CreateGeneralCargoCteDocument::class)->handle($data);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['data' => $exception->getMessage()]);
        }
    }

    /** @return list<array<string, string>> */
    private function uploadedNfeXmls(array $uploads): array
    {
        $files = [];
        $totalSizeBytes = 0;

        foreach ($uploads as $upload) {
            if (! $upload instanceof TemporaryUploadedFile) {
                throw new DomainException('Reenvie os XMLs de NF-e antes de salvar o rascunho.');
            }

            $totalSizeBytes += (int) $upload->getSize();
        }

        $maximumTotalSizeBytes = (int) config('dfe.nfe_xml_max_total_size_bytes', 10 * 1024 * 1024);

        if ($totalSizeBytes > $maximumTotalSizeBytes) {
            $maximumTotalSizeMegabytes = number_format($maximumTotalSizeBytes / 1024 / 1024, 1, ',', '.');
            throw new DomainException("Os XMLs de NF-e devem totalizar no máximo {$maximumTotalSizeMegabytes} MB.");
        }

        foreach ($uploads as $upload) {

            $files[] = [
                'filename' => $upload->getClientOriginalName(),
                'xml' => (string) $upload->get(),
            ];
        }

        return $files;
    }

    /** @return array{filename: string, contents: string}|null */
    private function uploadedCartaAttachment(mixed $upload): ?array
    {
        if ($upload === null || $upload === []) {
            return null;
        }

        if (! $upload instanceof TemporaryUploadedFile) {
            throw new DomainException('Reenvie o anexo da carta antes de salvar o rascunho.');
        }

        return [
            'filename' => basename(str_replace('\\', '/', $upload->getClientOriginalName())),
            'contents' => (string) $upload->get(),
        ];
    }

    /** @return list<\Filament\Forms\Components\Component> */
    private function companyFields(string $statePath, string $label): array
    {
        return [
            Select::make($statePath)
                ->label($label)
                ->placeholder('Buscar por razão social ou CNPJ')
                ->searchable()
                ->required()
                ->getSearchResultsUsing(fn (string $search): array => $this->companySearchResults($search))
                ->getOptionLabelUsing(fn (mixed $value): ?string => $this->companyOptionLabel($value))
                ->createOptionForm(CompanyResource::companyFormSchema(includeStatus: false))
                ->createOptionAction(fn (FormAction $action): FormAction => $action->visible(fn (): bool => CompanyResource::canCreate()))
                ->createOptionUsing(function (array $data): int {
                    abort_unless(CompanyResource::canCreate(), 403);

                    return (int) Company::query()->create([...$data, 'is_active' => true])->getKey();
                })
                ->live()
                ->afterStateUpdated(function () use ($statePath): void {
                    if (! $this->applyingNfeSuggestions) {
                        $this->manualCompanySelections[$statePath] = true;
                    }
                }),
            Placeholder::make($statePath.'_details')
                ->label('Dados cadastrais para conferência')
                ->content(fn (Get $get): HtmlString => $this->companyDetails($get($statePath)))
                ->visible(fn (Get $get): bool => filled($get($statePath)))
                ->columnSpanFull(),
        ];
    }

    /** @return array<int, string> */
    private function companySearchResults(string $search): array
    {
        $cnpj = Company::normalizeCnpj($search);

        return Company::query()
            ->where('is_active', true)
            ->where(function ($query) use ($search, $cnpj): void {
                $query->where('name', 'like', '%'.$search.'%');

                if ($cnpj !== '') {
                    $query->orWhere('cnpj', 'like', '%'.$cnpj.'%');
                }
            })
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'cnpj'])
            ->mapWithKeys(fn (Company $company): array => [$company->id => $this->formatCompanyOption($company)])
            ->all();
    }

    private function companyOptionLabel(mixed $value): ?string
    {
        $company = is_numeric($value)
            ? Company::query()->where('is_active', true)->find((int) $value)
            : null;

        return $company === null ? null : $this->formatCompanyOption($company);
    }

    private function formatCompanyOption(Company $company): string
    {
        return $company->name.' · CNPJ '.$company->cnpj;
    }

    private function companyDetails(mixed $value): HtmlString
    {
        $company = is_numeric($value)
            ? Company::query()->where('is_active', true)->find((int) $value)
            : null;

        if ($company === null) {
            return new HtmlString('');
        }

        $address = implode(', ', array_filter([
            $company->street,
            $company->number,
            $company->complement,
            $company->district,
            $company->city.' / '.$company->state,
            $company->zipcode,
        ]));

        return new HtmlString(implode('<br>', array_map('e', [
            $company->name.' · CNPJ '.$company->cnpj,
            'IE: '.($company->ie ?: 'não informada'),
            $address,
            'Município IBGE: '.$company->ibge_code,
        ])));
    }

    /** @return list<\Filament\Forms\Components\Component> */
    private function partyFields(string $path): array
    {
        $required = fn (Get $get): bool => $this->partyIsRequired($path, $get);

        return [
            TextInput::make($path.'.cnpj')
                ->label('CNPJ')
                ->required(fn (Get $get): bool => $this->partyIsRequired($path, $get) && blank($get($path.'.cpf')))
                ->maxLength(18),
            TextInput::make($path.'.cpf')
                ->label('CPF')
                ->required(fn (Get $get): bool => $this->partyIsRequired($path, $get) && blank($get($path.'.cnpj')))
                ->maxLength(14),
            TextInput::make($path.'.name')->label('Razão social/nome')->required($required)->maxLength(60),
            TextInput::make($path.'.ie')
                ->label('Inscrição estadual')
                ->helperText('Informe o dado cadastral da parte; a situação do tomador é escolhida em campo separado.'),
            TextInput::make($path.'.street')->label('Logradouro')->required($required)->maxLength(60),
            TextInput::make($path.'.number')->label('Número')->required($required)->maxLength(60),
            TextInput::make($path.'.complement')->label('Complemento')->maxLength(60),
            TextInput::make($path.'.district')->label('Bairro')->required($required)->maxLength(60),
            ...$this->citySelect($path, 'Município', 'city', $required),
            TextInput::make($path.'.zipcode')->label('CEP')->required($required)->maxLength(9),
        ];
    }

    /**
     * @param  bool|callable  $required
     * @return list<\Filament\Forms\Components\Component>
     */
    private function citySelect(string $path, string $label, string $cityNameField, mixed $required = true): array
    {
        return [
            Select::make($path.'.ibge')
                ->label($label)
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => City::query()
                    ->whereLike('name', '%'.$search.'%')
                    ->orderBy('name')
                    ->limit(50)
                    ->get(['ibge_code', 'name', 'state'])
                    ->mapWithKeys(fn (City $city): array => [$city->ibge_code => $city->name.' / '.$city->state])
                    ->all())
                ->getOptionLabelUsing(function (?string $value): ?string {
                    if ($value === null) {
                        return null;
                    }

                    $city = City::query()->where('ibge_code', $value)->first(['name', 'state']);

                    return $city === null ? null : $city->name.' / '.$city->state;
                })
                ->required($required)
                ->live()
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) use ($path, $cityNameField): void {
                    $city = City::query()->where('ibge_code', $state)->first(['name', 'state']);

                    if ($city === null) {
                        return;
                    }

                    $set($path.'.'.$cityNameField, $city->name);
                    $set($path.'.uf', $city->state);

                    if (in_array($path, ['origin', 'destination'], true)) {
                        $originUf = $path === 'origin' ? $city->state : (string) $get('origin.uf');
                        $destinationUf = $path === 'destination' ? $city->state : (string) $get('destination.uf');

                        if ($originUf !== '' && $destinationUf !== '') {
                            $this->refreshCfopSuggestion($originUf, $destinationUf, $get, $set);
                        }
                    }
                }),
            Hidden::make($path.'.'.$cityNameField),
            Hidden::make($path.'.uf'),
        ];
    }

    private function partyIsRequired(string $path, Get $get): bool
    {
        return $path === 'taker' && (string) $get('taker_code') === '4';
    }

    private function refreshCfopSuggestion(string $originUf, string $destinationUf, Get $get, Set $set): void
    {
        $previousSuggestion = (string) ($get('cfop_suggestion') ?? '');
        $currentCfop = (string) ($get('cfop') ?? '');
        $suggestion = match (true) {
            $originUf === 'SP' && $destinationUf === 'SP' => '5353',
            $originUf !== 'SP' && $originUf === $destinationUf => '5932',
            $originUf !== 'SP' => '6932',
            default => '',
        };

        if ($currentCfop === '' || $currentCfop === $previousSuggestion) {
            $set('cfop', $suggestion);
        }

        $set('cfop_suggestion', $suggestion);
    }

    private function cfopHelper(Get $get): string
    {
        $suggestion = (string) ($get('cfop_suggestion') ?? '');

        if ($suggestion !== '') {
            return "Sugestão pela rota: {$suggestion}. O campo é editável; a emissão validará as regras de prefixo e 5932/6932.";
        }

        return 'Para início em SP e término em outra UF, informe o CFOP de transporte aplicável (prefixo 6), como 6352 ou 6353 conforme a operação.';
    }

    private function applyNfeImport(mixed $state, Get $get, Set $set): void
    {
        $uploads = is_array($state) ? array_values($state) : ($state instanceof TemporaryUploadedFile ? [$state] : []);
        $documents = [];
        $this->nfeImportError = null;

        try {
            $totalSizeBytes = 0;

            foreach ($uploads as $upload) {
                if (! $upload instanceof TemporaryUploadedFile) {
                    throw new DomainException('Reenvie os XMLs de NF-e para ler os dados e arquivar o original.');
                }

                $totalSizeBytes += (int) $upload->getSize();
            }

            $maximumTotalSizeBytes = (int) config('dfe.nfe_xml_max_total_size_bytes', 10 * 1024 * 1024);

            if ($totalSizeBytes > $maximumTotalSizeBytes) {
                $maximumTotalSizeMegabytes = number_format($maximumTotalSizeBytes / 1024 / 1024, 1, ',', '.');
                throw new DomainException("Os XMLs de NF-e devem totalizar no máximo {$maximumTotalSizeMegabytes} MB.");
            }

            foreach ($uploads as $upload) {
                $filename = basename(str_replace('\\', '/', $upload->getClientOriginalName()));
                $data = app(ExtractNfeReference::class)->extractFromXml((string) $upload->get(), $filename);
                $documents[] = ['filename' => $filename, 'data' => $data];
            }
        } catch (DomainException $exception) {
            $this->nfeImportError = $exception->getMessage();
        }

        $suggestions = $this->suggestionsFromNfeImports($documents);
        $this->applyNfeSuggestions($suggestions, $get, $set);
        $this->nfeImportPreview = $documents;
        $this->nfeImportWarnings = $this->nfeImportWarnings($documents);
    }

    /**
     * @param  list<array{filename: string, data: array<string, mixed>}>  $documents
     * @return array<string, mixed>
     */
    private function suggestionsFromNfeImports(array $documents): array
    {
        if ($documents === []) {
            return [];
        }

        $imports = array_column($documents, 'data');
        $suggestions = [];

        foreach (['issuer' => 'rem_company_id', 'recipient' => 'dest_company_id'] as $source => $target) {
            $party = $this->commonImportedParty($imports, $source);

            if (filled($party['cnpj'] ?? null)) {
                $company = Company::query()
                    ->where('is_active', true)
                    ->where('cnpj', Company::normalizeCnpj((string) $party['cnpj']))
                    ->first();

                if ($company !== null) {
                    $suggestions[$target] = (string) $company->id;
                }
            }
        }

        $suggestions['cargo.value'] = $this->sumImportedValues($imports, 'cargo_value', 2);
        $suggestions['cargo.predominant_product'] = $this->commonImportedProduct($imports);
        $suggestions['cargo.measurements'] = $this->sumImportedMeasurements($imports);

        return $suggestions;
    }

    /** @param list<array<string, mixed>> $imports @return array<string, string> */
    private function commonImportedParty(array $imports, string $field): array
    {
        $party = $imports[0][$field] ?? [];

        foreach (array_slice($imports, 1) as $import) {
            if (($import[$field] ?? []) !== $party) {
                return [];
            }
        }

        return is_array($party) ? $party : [];
    }

    /** @param list<array<string, mixed>> $imports */
    private function commonImportedProduct(array $imports): ?string
    {
        $names = [];

        foreach ($imports as $import) {
            foreach ((array) ($import['products'] ?? []) as $product) {
                $name = trim((string) ($product['name'] ?? ''));

                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }

        return count($names) === 1 ? (string) array_key_first($names) : null;
    }

    /** @param list<array<string, mixed>> $imports */
    private function sumImportedValues(array $imports, string $field, int $scale): ?string
    {
        $total = 0;

        foreach ($imports as $import) {
            $value = $import[$field] ?? null;

            if (! is_string($value) || $value === '') {
                return null;
            }

            $total = ExactDecimal::add(
                $total,
                ExactDecimal::parse($value, $scale, (string) ($import['access_key'] ?? 'NF-e importada'), $field),
                (string) ($import['access_key'] ?? 'NF-e importada'),
                $field,
            );
        }

        return $imports === [] ? null : ExactDecimal::format($total, $scale);
    }

    /**
     * @param  list<array<string, mixed>>  $imports
     * @return list<array{unit: string, measure: string, quantity: string}>|null
     */
    private function sumImportedMeasurements(array $imports): ?array
    {
        $measurementsByImport = [];

        foreach ($imports as $import) {
            $measurements = [];

            foreach ((array) ($import['measurements'] ?? []) as $measurement) {
                $key = (string) ($measurement['unit'] ?? '').'|'.(string) ($measurement['measure'] ?? '');
                $measurements[$key] = (string) ($measurement['quantity'] ?? '');
            }

            $measurementsByImport[] = $measurements;
        }

        if ($measurementsByImport === []) {
            return null;
        }

        $result = [];

        foreach (array_keys($measurementsByImport[0]) as $key) {
            [$unit, $measure] = explode('|', $key, 2);
            $total = 0;
            $complete = true;

            foreach ($measurementsByImport as $position => $measurements) {
                $quantity = $measurements[$key] ?? null;

                if (! is_string($quantity) || $quantity === '') {
                    $complete = false;
                    break;
                }

                $total = ExactDecimal::add($total, ExactDecimal::parse($quantity, 4, 'NF-e '.$position, 'quantidade'), 'NF-e '.$position, 'quantidade');
            }

            if ($complete) {
                $result[] = ['unit' => $unit, 'measure' => $measure, 'quantity' => ExactDecimal::format($total, 4)];
            }
        }

        return $result === [] ? null : $result;
    }

    /**
     * @param  array<string, mixed>  $suggestions
     */
    private function applyNfeSuggestions(array $suggestions, Get $get, Set $set): void
    {
        $paths = array_unique([...array_keys($this->nfeImportSuggestions), ...array_keys($suggestions)]);

        $this->applyingNfeSuggestions = true;

        try {
            foreach ($paths as $path) {
                if (str_ends_with($path, '_company_id') && ($this->manualCompanySelections[$path] ?? false)) {
                    continue;
                }

                $current = $get($path);
                $previousExists = array_key_exists($path, $this->nfeImportSuggestions);
                $nextExists = array_key_exists($path, $suggestions);
                $previous = $this->nfeImportSuggestions[$path] ?? null;
                $next = $suggestions[$path] ?? null;

                if ($previousExists && $this->sameSuggestedValue($current, $previous, $path)) {
                    $set($path, $nextExists ? $next : $this->emptySuggestionValue($path));
                } elseif (! $previousExists && $nextExists && $this->isBlankSuggestionTarget($current, $path)) {
                    if ($next !== null) {
                        $set($path, $next);
                    }
                }
            }
        } finally {
            $this->applyingNfeSuggestions = false;
        }

        $this->nfeImportSuggestions = $suggestions;
    }

    private function sameSuggestedValue(mixed $current, mixed $suggested, string $path): bool
    {
        if (str_ends_with($path, '_company_id')) {
            return (string) $current === (string) $suggested;
        }

        if ($path === 'cargo.measurements') {
            return $this->normalizeMeasurements($current) === $this->normalizeMeasurements($suggested);
        }

        if ($path === 'cargo.value') {
            return $this->normalizeMoney($current) === $this->normalizeMoney($suggested);
        }

        return (string) ($current ?? '') === (string) ($suggested ?? '');
    }

    private function isBlankSuggestionTarget(mixed $current, string $path): bool
    {
        if ($path === 'cargo.measurements') {
            foreach ($this->normalizeMeasurements($current) as $measurement) {
                if ($measurement['unit'] !== '' || $measurement['measure'] !== '' || $measurement['quantity'] !== '') {
                    return false;
                }
            }

            return true;
        }

        if ($path === 'cargo.value') {
            return in_array($this->normalizeMoney($current), ['', '0.00'], true);
        }

        return blank($current);
    }

    private function normalizeMoney(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        try {
            return ExactDecimal::format(ExactDecimal::parse($value, 2, 'NF-e importada', 'vNF'), 2);
        } catch (DomainException) {
            return $value;
        }
    }

    private function emptySuggestionValue(string $path): mixed
    {
        return $path === 'cargo.measurements' ? [] : '';
    }

    /** @return list<array{unit: string, measure: string, quantity: string}> */
    private function normalizeMeasurements(mixed $measurements): array
    {
        if (! is_array($measurements)) {
            return [];
        }

        return array_values(array_map(fn (array $measurement): array => [
            'unit' => (string) ($measurement['unit'] ?? ''),
            'measure' => (string) ($measurement['measure'] ?? ''),
            'quantity' => (string) ($measurement['quantity'] ?? ''),
        ], array_filter($measurements, 'is_array')));
    }

    /** @param list<array{filename: string, data: array<string, mixed>}> $documents */
    private function nfeImportWarnings(array $documents): array
    {
        $warnings = [];

        foreach ($documents as $document) {
            foreach ((array) ($document['data']['warnings'] ?? []) as $warning) {
                $warnings[] = $document['filename'].': '.$warning;
            }
        }

        $imports = array_column($documents, 'data');

        foreach (['issuer' => 'remetente', 'recipient' => 'destinatário'] as $field => $label) {
            $party = $this->commonImportedParty($imports, $field);
            $cnpj = Company::normalizeCnpj((string) ($party['cnpj'] ?? ''));

            if ($cnpj === '' || ! Company::query()->where('is_active', true)->where('cnpj', $cnpj)->exists()) {
                $warnings[] = "A NF-e não permitiu correspondência por CNPJ do {$label}; selecione ou cadastre a empresa manualmente.";
            }
        }

        foreach (['issuer' => 'remetentes', 'recipient' => 'destinatários'] as $field => $label) {
            if (count($imports) > 1 && $this->commonImportedParty($imports, $field) === []) {
                $warnings[] = "As NF-es têm {$label} diferentes; informe a parte correta manualmente.";
            }
        }

        if (count(array_filter(array_map(fn (array $import): ?string => $import['cargo_value'] ?? null, $imports))) !== count($imports)) {
            $warnings[] = 'Total vNF ausente em pelo menos uma NF-e; preencha o valor total da carga manualmente.';
        }

        if (count(array_filter(array_map(fn (array $import): ?string => $import['gross_weight_kg'] ?? null, $imports))) !== count($imports)) {
            $warnings[] = 'NF-e sem peso bruto; informe peso estimado em kg ou unidades transportadas no CT-e.';
        }

        return array_values(array_unique($warnings));
    }

    private function nfeImportPreviewContent(): HtmlString
    {
        $lines = [];

        if (filled($this->nfeImportError)) {
            $lines[] = 'Erro de leitura: '.$this->nfeImportError;
        }

        foreach ($this->nfeImportPreview as $document) {
            $data = (array) ($document['data'] ?? []);
            $lines[] = 'NF-e '.(string) ($data['access_key'] ?? '').' — '.(string) ($document['filename'] ?? '');

            foreach (['issuer' => 'Remetente', 'recipient' => 'Destinatário'] as $source => $label) {
                $cnpj = Company::normalizeCnpj((string) data_get($data, $source.'.cnpj', ''));
                $company = $cnpj === '' ? null : Company::query()
                    ->where('is_active', true)
                    ->where('cnpj', $cnpj)
                    ->first(['name', 'cnpj']);
                $lines[] = $company === null
                    ? "{$label}: sem correspondência por CNPJ em empresa ativa; escolha ou cadastre manualmente."
                    : "{$label} localizado no cadastro: {$company->name} · CNPJ {$company->cnpj}; confirme no seletor.";
            }

            foreach ((array) ($data['products'] ?? []) as $product) {
                $lines[] = trim((string) ($product['name'] ?? '')).' | NCM '.(string) ($product['ncm'] ?? '')
                    .' | '.(string) ($product['quantity'] ?? '').' '.(string) ($product['unit'] ?? '')
                    .' | vProd R$ '.(string) ($product['value'] ?? '');
            }

            $lines[] = 'Total vNF: '.(string) ($data['cargo_value'] ?? 'não informado')
                .' | peso bruto: '.(string) ($data['gross_weight_kg'] ?? 'não informado').' kg'
                .' | peso líquido: '.(string) ($data['net_weight_kg'] ?? 'não informado').' kg'
                .' | volumes: '.(string) ($data['volume_count'] ?? 'não informado');
        }

        foreach ($this->nfeImportWarnings as $warning) {
            $lines[] = 'Atenção: '.$warning;
        }

        $lines[] = 'A rota física, o tomador, a situação de ICMS, o CFOP e o frete não são deduzidos da NF-e.';

        return new HtmlString(implode('<br>', array_map('e', $lines)));
    }
}
