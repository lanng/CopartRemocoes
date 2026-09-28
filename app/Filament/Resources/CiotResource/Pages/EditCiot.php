<?php

namespace App\Filament\Resources\CiotResource\Pages;

use App\Filament\Resources\CiotResource;
use App\Filament\Support\CiotEmissionNotice;
use App\Services\Ciot\EmitCiotDeclaration;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditCiot extends EditRecord
{
    protected static string $resource = CiotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()->label('Visualizar'),
            Actions\DeleteAction::make()->label('Excluir'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * @return list<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('saveAndEmit')
                ->label('Salvar e emitir')
                ->color('success')
                ->icon('heroicon-m-paper-airplane')
                ->action('saveAndEmit'),
            ...parent::getFormActions(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CiotResource::transformFormData($data);
    }

    /**
     * Salva as correções e envia a declaração à ANTT na hora: o resultado
     * (emitido/falha) aparece no infolist para o qual redirecionamos.
     */
    public function saveAndEmit(): void
    {
        $this->save();

        $result = app(EmitCiotDeclaration::class)->emit($this->getRecord());

        (new CiotEmissionNotice(queuedTitle: 'CIOT salvo — emissão em processamento'))->send($result);

        $this->redirect($this->getRedirectUrl());
    }
}
