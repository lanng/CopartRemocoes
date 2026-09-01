<?php

namespace App\Filament\Support;

use App\Models\IntegrationInboxItem;
use App\Services\MicrosoftGraph\RemovalRequests\ResolveRemovalRequestImport;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Actions\Action;

class ReviewRemovalRequestAction
{
    public static function make(): Action
    {
        return Action::make('reviewRemovalRequest')
            ->label('Revisar importação')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (IntegrationInboxItem $record): bool => $record->isRemovalRequest()
                && $record->status === 'pending'
                && ($record->proposed_changes !== null || $record->candidate_pdf_path !== null))
            ->modalHeading(fn (IntegrationInboxItem $record): string => "Revisar importação - {$record->extracted_vehicle_id}")
            ->modalDescription('Selecione as alterações propostas que devem ser aplicadas ao registro.')
            ->modalSubmitActionLabel('Aplicar')
            ->form(fn (IntegrationInboxItem $record): array => [
                CheckboxList::make('fields')
                    ->label('Campos para aplicar')
                    ->options(collect($record->proposed_changes ?? [])
                        ->reject(fn (array $change, string $field): bool => $field === 'pdf_path')
                        ->mapWithKeys(fn (array $change, string $field): array => [$field => IntegrationInboxItem::proposedFieldLabel($field)])
                        ->all())
                    ->columns(1),
                Toggle::make('replace_pdf')->label('Substituir PDF candidato')->default(false),
            ])
            ->action(function (IntegrationInboxItem $record, array $data): void {
                app(ResolveRemovalRequestImport::class)->apply(
                    $record,
                    auth()->user(),
                    array_map('strval', $data['fields'] ?? []),
                    (bool) ($data['replace_pdf'] ?? false),
                );
            });
    }
}
