<?php

namespace App\Filament\Actions;

use App\Models\CteDocument;
use App\Models\MdfeDocument;
use App\Services\Dfe\DownloadFiscalDocumentArchive;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DownloadFiscalXmlsAction
{
    /**
     * @param  class-string<resource>  $resource
     */
    public static function make(string $resource, string $documentType): Action
    {
        return Action::make('downloadXmls')
            ->label('Baixar XMLs por período')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->authorize(fn (): bool => auth()->check() && $resource::canViewAny())
            ->modalHeading('Baixar XMLs por período')
            ->modalDescription('Baixe um ZIP com todos os XMLs pela data de emissão, no horário de Brasília. O período inclui todas as situações e não depende dos filtros ou da página da listagem.')
            ->modalSubmitActionLabel('Baixar ZIP')
            ->modalWidth('md')
            ->form([
                DatePicker::make('start_date')
                    ->label('Data inicial de emissão')
                    ->default(fn (): string => now('America/Sao_Paulo')->subMonthNoOverflow()->startOfMonth()->toDateString())
                    ->required()
                    ->rules(['date_format:Y-m-d'])
                    ->validationMessages([
                        'required' => 'Informe a data inicial.',
                        'date_format' => 'Informe uma data inicial válida.',
                    ]),
                DatePicker::make('end_date')
                    ->label('Data final de emissão')
                    ->default(fn (): string => now('America/Sao_Paulo')->subMonthNoOverflow()->endOfMonth()->toDateString())
                    ->required()
                    ->rules(['date_format:Y-m-d'])
                    ->afterOrEqual('start_date')
                    ->validationMessages([
                        'required' => 'Informe a data final.',
                        'date_format' => 'Informe uma data final válida.',
                        'after_or_equal' => 'A data final deve ser igual ou posterior à data inicial.',
                    ]),
            ])
            ->action(function (array $data) use ($resource, $documentType): ?StreamedResponse {
                abort_unless(auth()->check() && $resource::canViewAny(), 403);

                $startDate = CarbonImmutable::parse($data['start_date'], 'America/Sao_Paulo')->startOfDay();
                $endDate = CarbonImmutable::parse($data['end_date'], 'America/Sao_Paulo')->startOfDay();
                $files = $resource::getEloquentQuery()
                    ->select(['id', 'access_key', 'xml_path'])
                    ->where('issued_at', '>=', $startDate->setTimezone(config('app.timezone')))
                    ->where('issued_at', '<', $endDate->addDay()->setTimezone(config('app.timezone')))
                    ->whereNotNull('xml_path')
                    ->where('xml_path', '!=', '')
                    ->lazyById(100)
                    ->map(fn (CteDocument|MdfeDocument $document): array => [
                        'path' => (string) $document->xml_path,
                        'name' => strtolower((string) ($document->access_key ?? $document->id))."-{$documentType}.xml",
                    ]);
                $filename = "{$documentType}-xmls-{$startDate->toDateString()}-a-{$endDate->toDateString()}.zip";

                try {
                    $response = app(DownloadFiscalDocumentArchive::class)->handle($files, $filename);
                } catch (DomainException $exception) {
                    Notification::make()->title('Exportação não concluída')->body($exception->getMessage())->warning()->send();

                    return null;
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()->title('Exportação não concluída')
                        ->body('Não foi possível gerar o ZIP. Tente novamente em instantes.')
                        ->danger()->send();

                    return null;
                }

                if ($response === null) {
                    Notification::make()->title('Nenhum XML encontrado no período')
                        ->body('Não há XMLs arquivados com data de emissão no período informado.')
                        ->warning()->send();
                }

                return $response;
            });
    }
}
