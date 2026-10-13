<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
    x-on:paste.capture="
        const input = $event.target;
        const pastedText = $event.clipboardData?.getData('text/plain') ?? '';
        const plateTokens = pastedText.trim().split(/[\s,;]+/).filter(Boolean);
        const isPlateList = plateTokens.length >= 2 && plateTokens.every((token) =>
            /^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/i.test(token.replaceAll('-', ''))
        );

        if (
            input instanceof HTMLInputElement &&
            input.getAttribute('wire:model.live.debounce.500ms') === 'tableSearch' &&
            /[\r\n]/.test(pastedText) &&
            isPlateList
        ) {
            $event.preventDefault();
            input.setRangeText(plateTokens.join(', '), input.selectionStart, input.selectionEnd, 'end');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    "
>
    <div class="flex flex-col gap-y-6">
        <x-filament-panels::resources.tabs />

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

        {{ $this->table }}

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
    </div>
</x-filament-panels::page>
