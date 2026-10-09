<x-filament-panels::page>
    <form wire:submit="prepare" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end gap-3">
            <x-filament::button
                tag="a"
                color="gray"
                :href="$this->getBackUrl()"
            >
                {{ $this->isEditing() ? 'Voltar para o MDF-e' : 'Voltar para manifestos' }}
            </x-filament::button>

            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="prepare">
                <span wire:loading.remove wire:target="prepare">{{ $this->isEditing() ? 'Salvar alterações' : 'Preparar e enfileirar MDF-e' }}</span>
                <span wire:loading wire:target="prepare">{{ $this->isEditing() ? 'Salvando alterações...' : 'Preparando CIOT e enfileirando MDF-e...' }}</span>
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
