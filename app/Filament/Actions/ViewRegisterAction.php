<?php

namespace App\Filament\Actions;

use App\Enums\RegisterStatusEnum;
use App\Filament\Resources\IntegrationInboxItemResource;
use App\Filament\Resources\RegisterResource;
use App\Models\Register;
use Closure;
use Filament\Actions\StaticAction;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\Action;
use Illuminate\Database\Eloquent\Model;

class ViewRegisterAction
{
    /**
     * @param  Closure(Model): ?Register  $registerResolver
     */
    public static function make(Closure $registerResolver): Action
    {
        return Action::make('viewRegister')
            ->label('Ver registro')
            ->icon('heroicon-o-eye')
            ->modalHeading('Ver registro')
            ->modalWidth(MaxWidth::FiveExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalCancelActionLabel('Fechar')
            ->modalSubmitAction(false)
            ->infolist(function (Infolist $infolist, Model $record) use ($registerResolver): Infolist {
                return self::infolist($infolist, $registerResolver($record));
            })
            ->extraModalFooterActions(function (Model $record) use ($registerResolver): array {
                $register = $registerResolver($record);

                if (! $register) {
                    return [];
                }

                return [
                    StaticAction::make('openRegister')
                        ->label('Abrir registro completo')
                        ->link()
                        ->url(RegisterResource::getUrl('view', ['record' => $register])),
                ];
            })
            ->visible(fn (Model $record): bool => $registerResolver($record) instanceof Register);
    }

    private static function infolist(Infolist $infolist, ?Register $register): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Identificação')
                    ->columnSpanFull()
                    ->columns([
                        'sm' => 2,
                        'xl' => 3,
                    ])
                    ->schema([
                        TextEntry::make('vehicle_model')->label('Veículo'),
                        TextEntry::make('vehicle_plate')->label('Placa'),
                        TextEntry::make('vehicle_id')->label('Código do veículo'),
                        TextEntry::make('company')
                            ->label('Empresa')
                            ->formatStateUsing(fn ($state): string => $state instanceof \BackedEnum ? (string) $state->value : (string) $state)
                            ->placeholder('Não informado'),
                        TextEntry::make('status')
                            ->label('Situação')
                            ->badge()
                            ->color(fn (RegisterStatusEnum $state): string => $state->color())
                            ->formatStateUsing(fn (RegisterStatusEnum $state): string => $state->localizedLabel()),
                        TextEntry::make('payment_code')->label('Código pagamento')->placeholder('Não informado'),
                        TextEntry::make('insurance')->label('Seguradora')->placeholder('Não informado'),
                    ]),
                Section::make('Rota e prazos')
                    ->columnSpanFull()
                    ->columns([
                        'sm' => 2,
                        'xl' => 3,
                    ])
                    ->schema([
                        TextEntry::make('origin_city')->label('Origem'),
                        TextEntry::make('destination_city')->label('Destino'),
                        TextEntry::make('deadline_withdraw')
                            ->label('Retirada até')
                            ->date('d/m/Y')
                            ->placeholder('Não informado'),
                        TextEntry::make('deadline_delivery')
                            ->label('Entrega até')
                            ->date('d/m/Y')
                            ->placeholder('Não informado'),
                        TextEntry::make('delivery_confirmed_at')
                            ->label('Entrega confirmada')
                            ->dateTime('d/m/Y H:i')
                            ->timezone('America/Sao_Paulo')
                            ->placeholder('Não informado'),
                    ]),
                Section::make('Operação')
                    ->columnSpanFull()
                    ->columns([
                        'sm' => 2,
                        'xl' => 3,
                    ])
                    ->schema([
                        TextEntry::make('collected_date')
                            ->label('Data da recolha')
                            ->date('d/m/Y')
                            ->placeholder('Não informado'),
                        TextEntry::make('driver')->label('Motorista')->placeholder('Não informado'),
                        TextEntry::make('driver_plate')->label('Placa guincho')->placeholder('Não informado'),
                        TextEntry::make('tow_yard')->label('Pátio')->placeholder('Não informado'),
                    ]),
                Section::make('Financeiro')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('value')
                            ->label('Valor do serviço')
                            ->money('BRL', locale: 'pt_BR')
                            ->placeholder('Não informado'),
                        TextEntry::make('fipe_value')
                            ->label('Valor FIPE')
                            ->money('BRL', locale: 'pt_BR')
                            ->placeholder('Não informado'),
                    ]),
                Section::make('Observações')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('notes')->placeholder('Não informado'),
                    ]),
                Section::make('Revisão de integração')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('integration_attention')
                            ->label('Situação')
                            ->state(fn (?Register $register): ?string => $register?->unresolvedRemovalImports()->exists() ? 'Há uma revisão de e-mail pendente.' : null)
                            ->color('warning')
                            ->url(function (?Register $register): ?string {
                                $item = $register?->unresolvedRemovalImports()->first();

                                return $item === null
                                    ? null
                                    : IntegrationInboxItemResource::getUrl('view', ['record' => $item]);
                            })
                            ->visible(fn (?Register $register): bool => $register?->unresolvedRemovalImports()->exists() === true),
                    ]),
            ])
            ->record($register);
    }
}
