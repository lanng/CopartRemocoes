<?php

namespace App\Filament\Resources\CteEmissionResource\Pages;

use App\Filament\Resources\CteEmissionResource;
use App\Models\CteDocument;
use App\Models\User;
use App\Services\Cte\ConsolidateAlcoholNfes;
use App\Services\Cte\CreateAlcoholCteDocument as CreateAlcoholCteDocumentService;
use App\Services\Cte\ImportNfeXml;
use App\Services\Cte\QueueCteDocumentEmission;
use DomainException;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Leandrocfe\FilamentPtbrFormFields\Money;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CreateCteEmission extends CreateRecord
{
    protected static string $resource = CteEmissionResource::class;

    protected static ?string $title = 'Novo CT-e de álcool';

    /** @var list<string> */
    #[Locked]
    public array $deliveryMissingFields = [];

    private const DELIVERY_FIELDS = [
        'cnpj' => 'CNPJ',
        'cpf' => 'CPF',
        'name' => 'Nome do local de entrega',
        'ie' => 'Inscrição estadual',
        'street' => 'Logradouro',
        'number' => 'Número',
        'complement' => 'Complemento',
        'district' => 'Bairro',
        'ibge' => 'Código IBGE do município',
        'city' => 'Município',
        'state' => 'UF',
        'zipcode' => 'CEP',
    ];

    public function form(Form $form): Form
    {
        $maximumFileSizeKilobytes = (int) ceil(config('dfe.nfe_xml_max_file_size_bytes') / 1024);

        return $form
            ->schema([
                Section::make('Importar NF-es')
                    ->description('Selecione até '.config('dfe.nfe_xml_max_files_per_import').' XMLs de NF-e modelo 55 autorizadas, com limite de 10 MB por arquivo.')
                    ->schema([
                        FileUpload::make('nfe_xmls')
                            ->label('XMLs das NF-es')
                            ->multiple()
                            ->required()
                            ->storeFiles(false)
                            ->preserveFilenames()
                            ->acceptedFileTypes(['application/xml', 'text/xml', 'application/octet-stream'])
                            ->rules(['extensions:xml'])
                            ->maxSize($maximumFileSizeKilobytes)
                            ->maxFiles((int) config('dfe.nfe_xml_max_files_per_import'))
                            ->helperText('As notas precisam ter o mesmo remetente, destinatário fiscal, produtos e local físico de entrega.')
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?array $state): void {
                                $this->deliveryMissingFields = $this->missingDeliveryFieldsForUploads($state ?? []);
                                $complements = (array) $get('delivery_complements');

                                $set('delivery_complements', array_intersect_key(
                                    $complements,
                                    array_flip($this->deliveryMissingFields),
                                ));
                            }),
                        Placeholder::make('nfe_summary')
                            ->label('Resumo das NF-es')
                            ->content(fn (Get $get): HtmlString|string => $this->nfePreview($get('nfe_xmls')))
                            ->columnSpanFull(),
                        Section::make('Complementar dados ausentes da entrega física')
                            ->description('Preencha somente os campos que faltam nos XMLs selecionados.')
                            ->schema(fn (): array => $this->deliveryComplementFields())
                            ->visible(fn (): bool => $this->deliveryMissingFields !== [])
                            ->columns(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Frete e previsão')
                    ->columns(2)
                    ->schema([
                        Money::make('freight_value')
                            ->label('Valor do frete')
                            ->required(),
                        DatePicker::make('delivery_date')
                            ->label('Previsão de entrega')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->minDate(now('America/Sao_Paulo')->toDateString())
                            ->default(now('America/Sao_Paulo')->addDay()->toDateString()),
                        Placeholder::make('freight_notice')
                            ->label('Processamento')
                            ->content('As NF-es originais serão arquivadas. Salve o rascunho para revisar depois ou escolha “Salvar e enfileirar emissão” para iniciar o processamento fiscal.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return list<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('createAndQueue')
                ->label('Salvar e enfileirar emissão')
                ->color('success')
                ->icon('heroicon-m-paper-airplane')
                ->action('createAndQueue'),
            $this->getCreateFormAction(),
            ...(static::canCreateAnother() ? [$this->getCreateAnotherFormAction()] : []),
            $this->getCancelFormAction(),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Salvar rascunho');
    }

    public function createAndQueue(): void
    {
        if ($this->isCreating) {
            return;
        }

        $this->isCreating = true;

        try {
            $this->authorizeAccess();

            $data = $this->form->getState();
            $data = $this->mutateFormDataBeforeCreate($data);

            /** @var CteDocument $document */
            $document = $this->handleRecordCreation($data);
            $this->record = $document;

            try {
                app(QueueCteDocumentEmission::class)->handle($document);
            } catch (DomainException $exception) {
                Notification::make()
                    ->title('CT-e salvo como rascunho, mas não foi enfileirado.')
                    ->body($exception->getMessage())
                    ->warning()
                    ->send();

                $this->redirect(CteEmissionResource::getUrl('view', ['record' => $document]));

                return;
            }

            Notification::make()
                ->title('CT-e salvo e enfileirado para emissão.')
                ->success()
                ->send();

            $this->redirect(CteEmissionResource::getUrl('view', ['record' => $document]));
        } finally {
            $this->isCreating = false;
        }
    }

    protected function getCancelFormAction(): Actions\Action
    {
        return Actions\Action::make('discardImport')
            ->label('Descartar importação')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Descartar importação?')
            ->modalDescription('Os arquivos temporários enviados nesta tela serão removidos. NF-es fiscais arquivadas não são afetadas.')
            ->action('discardImport');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Rascunho de CT-e criado.';
    }

    protected function handleRecordCreation(array $data): Model
    {
        $operator = auth()->user();

        abort_unless($operator instanceof User, 403);

        try {
            $files = $this->prepareFiles($data);
            $document = app(CreateAlcoholCteDocumentService::class)->handle(
                files: $files,
                freightValue: (string) $data['freight_value'],
                operator: $operator,
                deliveryDate: (string) $data['delivery_date'],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'data.nfe_xmls' => $exception->getMessage(),
            ]);
        }

        $this->deleteTemporaryUploads((array) ($data['nfe_xmls'] ?? []));

        return $document;
    }

    public function discardImport(): void
    {
        $this->deleteTemporaryUploads((array) ($this->data['nfe_xmls'] ?? []));
        $this->redirect(CteEmissionResource::getUrl('index'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{filename: string, xml: string, operator_complements?: array<string, mixed>|null}>
     */
    private function prepareFiles(array $data): array
    {
        $uploads = $data['nfe_xmls'] ?? [];

        if (! is_array($uploads) || $uploads === []) {
            throw new DomainException('Selecione ao menos uma NF-e para importar ao CT-e.');
        }

        $complements = $this->complementValues((array) ($data['delivery_complements'] ?? []));
        $files = [];
        $usedComplementFields = [];
        $importer = app(ImportNfeXml::class);

        foreach ($uploads as $upload) {
            if (! $upload instanceof TemporaryUploadedFile) {
                throw new DomainException('Reenvie os arquivos XML das NF-es antes de salvar o rascunho.');
            }

            $filename = $upload->getClientOriginalName();
            $xml = (string) $upload->get();
            $extracted = $importer->handle($xml, $filename);
            $deliveryComplements = array_intersect_key(
                $complements,
                array_flip($extracted['delivery_missing_fields']),
            );
            $usedComplementFields = [...$usedComplementFields, ...array_keys($deliveryComplements)];

            $files[] = [
                'filename' => $filename,
                'xml' => $xml,
                'operator_complements' => $deliveryComplements === []
                    ? null
                    : ['delivery' => $deliveryComplements],
            ];
        }

        $unusedFields = array_diff(array_keys($complements), array_unique($usedComplementFields));

        if ($unusedFields !== []) {
            $firstUnusedField = (string) reset($unusedFields);

            throw new DomainException('O campo '.self::DELIVERY_FIELDS[$firstUnusedField].' não está ausente em nenhuma das NF-es selecionadas.');
        }

        return $files;
    }

    /** @param array<string, mixed> $complements @return array<string, string> */
    private function complementValues(array $complements): array
    {
        $values = [];

        foreach ($complements as $field => $value) {
            if (! is_string($field) || ! array_key_exists($field, self::DELIVERY_FIELDS) || ! is_string($value) || trim($value) === '') {
                throw new DomainException('Informe um campo de entrega permitido e o respectivo complemento.');
            }

            $values[$field] = trim($value);
        }

        return $values;
    }

    /** @return list<TextInput> */
    private function deliveryComplementFields(): array
    {
        return array_map(
            fn (string $field): TextInput => TextInput::make('delivery_complements.'.$field)
                ->label(self::DELIVERY_FIELDS[$field])
                ->required()
                ->maxLength(255)
                ->helperText('Aplicado somente às NF-es em que este dado está ausente.'),
            $this->deliveryMissingFields,
        );
    }

    /** @param list<mixed> $uploads @return list<string> */
    private function missingDeliveryFieldsForUploads(array $uploads): array
    {
        $missingFields = [];
        $importer = app(ImportNfeXml::class);

        try {
            foreach ($uploads as $upload) {
                if (! $upload instanceof TemporaryUploadedFile) {
                    continue;
                }

                $extracted = $importer->handle((string) $upload->get(), $upload->getClientOriginalName());
                $missingFields = [...$missingFields, ...$extracted['delivery_missing_fields']];
            }
        } catch (DomainException) {
            return [];
        }

        return array_values(array_intersect(
            array_keys(self::DELIVERY_FIELDS),
            array_unique($missingFields),
        ));
    }

    /** @param list<mixed> $uploads */
    private function deleteTemporaryUploads(array $uploads): void
    {
        foreach ($uploads as $upload) {
            if ($upload instanceof TemporaryUploadedFile && $upload->exists()) {
                $upload->delete();
            }
        }
    }

    private function nfePreview(mixed $uploads): HtmlString|string
    {
        if (! is_array($uploads) || $uploads === []) {
            return 'Selecione as NF-es para ver os totais e os destinos antes de salvar o rascunho.';
        }

        try {
            $documents = [];
            $importer = app(ImportNfeXml::class);

            foreach ($uploads as $upload) {
                if (! $upload instanceof TemporaryUploadedFile) {
                    continue;
                }

                $documents[] = $importer->handle((string) $upload->get(), $upload->getClientOriginalName());
            }

            if ($documents === []) {
                return 'Selecione novamente os XMLs para visualizar o resumo.';
            }

            $summary = app(ConsolidateAlcoholNfes::class)->handle($documents);
        } catch (DomainException $exception) {
            return new HtmlString('<p class="text-sm text-danger-600">Não foi possível consolidar as NF-es: '.e($exception->getMessage()).'</p>');
        }

        $notes = array_map(fn (array $document): string => sprintf(
            '<li><strong>NF-e %s · série %s</strong> — %s · chave %s</li>',
            e($document['number']),
            e($document['series']),
            e($document['source_filename']),
            e($document['access_key']),
        ), $summary['documents']);
        $recipient = $summary['recipient'];
        $delivery = $summary['delivery'];
        $missingFields = array_values(array_unique($summary['delivery_missing_fields']));
        $missingLabels = array_map(
            fn (string $field): string => e(self::DELIVERY_FIELDS[$field] ?? $field),
            $missingFields,
        );

        $content = '<ul class="list-disc space-y-1 pl-5">'.implode('', $notes).'</ul>';
        $content .= '<p class="mt-3"><strong>Carga consolidada:</strong> R$ '.number_format((float) $summary['cargo_value'], 2, ',', '.');
        $content .= ' · '.number_format((float) $summary['cargo_liters'], 4, ',', '.').' L';
        $content .= ' · '.number_format((int) $summary['net_weight_kg'], 0, ',', '.').' kg</p>';
        $content .= '<p class="mt-2"><strong>Tomador fiscal:</strong> '.e($recipient['name']).' — '.e($recipient['city']).' / '.e($recipient['state']).'</p>';
        $content .= '<p><strong>Local físico de entrega:</strong> '.e($delivery['name']).' — '.e($delivery['street']).', '.e($delivery['number']).' — '.e($delivery['city']).' / '.e($delivery['state']).'</p>';
        $content .= '<p class="mt-2"><strong>Campos de entrega ausentes:</strong> '.($missingLabels === [] ? 'nenhum' : implode(', ', $missingLabels)).'</p>';

        return new HtmlString($content);
    }
}
