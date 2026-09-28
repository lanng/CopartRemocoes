<?php

namespace App\Filament\Resources\CiotResource\Pages;

use App\Filament\Resources\CiotResource;
use App\Filament\Support\CiotEmissionNotice;
use App\Models\Ciot;
use App\Services\Ciot\EmitCiotDeclaration;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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

        $result = app(EmitCiotDeclaration::class)->emit($record);

        (new CiotEmissionNotice)->send($result);

        $this->redirect(CiotResource::getUrl('view', ['record' => $result->ciot]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['public_id'] = (string) Str::uuid();

        return CiotResource::transformFormData($data);
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
