<?php

namespace App\Filament\Resources\CiotResource\Pages;

use App\Enums\CiotStatusEnum;
use App\Filament\Resources\CiotResource;
use App\Jobs\EmitCiotJob;
use App\Models\Ciot;
use App\Models\CiotPayer;
use App\Models\CiotVehicle;
use App\Services\Ciot\EmitCiotDeclaration;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

class CreateCiot extends CreateRecord
{
    protected static string $resource = CiotResource::class;

    /**
     * @return list<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('createAndEmit')
                ->label('Criar e emitir')
                ->color('success')
                ->icon('heroicon-m-paper-airplane')
                ->action('createAndEmit'),
            $this->getCreateFormAction(),
            ...(static::canCreateAnother() ? [$this->getCreateAnotherFormAction()] : []),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * Cria o rascunho e envia a declaração à ANTT na hora (o botão fica em
     * loading até a resposta), então redireciona para o infolist com o
     * resultado: emitido com os dados da ANTT, ou falha com a rejeição.
     */
    public function createAndEmit(): void
    {
        $this->authorizeAccess();

        $data = $this->form->getState();
        $data = $this->mutateFormDataBeforeCreate($data);

        /** @var Ciot $record */
        $record = $this->handleRecordCreation($data);

        try {
            $record = app(EmitCiotDeclaration::class)->handle($record, sync: true);
        } catch (Throwable $exception) {
            report($exception);

            EmitCiotJob::dispatch($record->id);

            Notification::make()
                ->title('CIOT criado — emissão em processamento')
                ->body('A ANTT não respondeu agora; a emissão ficou na fila com retry automático.')
                ->warning()
                ->send();

            $this->redirect(CiotResource::getUrl('view', ['record' => $record]));

            return;
        }

        $record->refresh();

        if ($record->status === CiotStatusEnum::ISSUED) {
            Notification::make()
                ->title('CIOT emitido: '.$record->fullNumber())
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Emissão rejeitada pela ANTT')
                ->body((string) $record->error_message)
                ->danger()
                ->send();
        }

        $this->redirect(CiotResource::getUrl('view', ['record' => $record]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $payer = CiotPayer::query()->findOrFail($data['payer_id']);
        $delivery = CiotPayer::query()->find($data['delivery_payer_id'] ?? null) ?? $payer;
        $vehicles = CiotVehicle::query()->whereIn('id', $data['vehicle_ids'] ?? [])->get();

        $data['public_id'] = (string) Str::uuid();
        $data['payer_cnpj'] = $payer->cnpj;
        $data['payer_name'] = $payer->name;
        $data['delivery_payer_cnpj'] = $delivery->cnpj;
        $data['delivery_payer_name'] = $delivery->name;
        $data['vehicles'] = $vehicles->map(fn (CiotVehicle $vehicle): array => $vehicle->snapshot())->all();
        $data['freight_value_cents'] = (int) round(((float) str_replace(',', '.', (string) $data['freight_value'])) * 100);
        unset($data['freight_value'], $data['vehicle_ids']);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Ciot $ciot */
        $ciot = static::getModel()::create($data);

        activity()
            ->performedOn($ciot)
            ->log('CIOT criado pelo painel (avulso).');

        return $ciot;
    }
}
