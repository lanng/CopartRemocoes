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

        @if ($this->hasActiveColumnFilters())
            <div class="flex justify-end">
                <button
                    type="button"
                    wire:click="clearColumnFilters"
                    class="text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                >
                    Limpar filtros das colunas
                </button>
            </div>
        @endif

        {{ $this->table }}

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
    </div>

    @once
        <script>
            document.addEventListener('click', (event) => {
                if (!window.matchMedia('(max-width: 767px)').matches) {
                    return;
                }

                const colorCell = event.target.closest('[data-register-record-url]');

                if (!colorCell) {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();
                window.location.assign(colorCell.dataset.registerRecordUrl);
            }, true);

            window.addEventListener('registers-copy-as-image', async (event) => {
                const rows = event.detail.rows ?? [];

                if (rows.length === 0) {
                    return;
                }

                const columns = [
                    { key: 'plate', label: 'Placa', width: 110, color: 'vehicle_plate_color', bold: true },
                    { key: 'model', label: 'Modelo', width: 170, color: 'vehicle_model_color' },
                    { key: 'origin', label: 'Origem', width: 150, color: 'origin_city_color' },
                    { key: 'destination', label: 'Destino', width: 150, color: 'destination_city_color' },
                    { key: 'withdraw', label: 'Coleta', width: 78, color: 'deadline_withdraw_color' },
                    { key: 'delivery', label: 'Entrega', width: 78, color: 'deadline_delivery_color' },
                    { key: 'status', label: 'Situação', width: 120 },
                    { key: 'location', label: 'Local', width: 120 },
                    { key: 'condition', label: 'Condição', width: 100 },
                    { key: 'value', label: 'Valor', width: 110 },
                ];
                const colors = {
                    blue: '#2563eb',
                    red: '#dc2626',
                    yellow: '#ca8a04',
                    green: '#15803d',
                    black: '#111827',
                };
                const rowHeight = 42;
                const headerHeight = 46;
                const scale = 2;
                const width = columns.reduce((total, column) => total + column.width, 0);
                const canvas = document.createElement('canvas');
                canvas.width = width * scale;
                canvas.height = (headerHeight + (rows.length * rowHeight)) * scale;

                const context = canvas.getContext('2d');
                context.scale(scale, scale);
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, width, headerHeight + (rows.length * rowHeight));
                context.font = '600 13px Arial, sans-serif';
                context.textBaseline = 'middle';

                let x = 0;
                columns.forEach((column) => {
                    context.fillStyle = '#f1f5f9';
                    context.fillRect(x, 0, column.width, headerHeight);
                    context.fillStyle = '#1f2937';
                    context.fillText(column.label, x + 10, headerHeight / 2, column.width - 16);
                    context.strokeStyle = '#cbd5e1';
                    context.strokeRect(x, 0, column.width, headerHeight);
                    x += column.width;
                });

                rows.forEach((row, rowIndex) => {
                    const y = headerHeight + (rowIndex * rowHeight);
                    let cellX = 0;

                    columns.forEach((column) => {
                        context.fillStyle = '#ffffff';
                        context.fillRect(cellX, y, column.width, rowHeight);
                        context.fillStyle = colors[row.colors?.[column.color]] ?? colors.black;
                        context.font = `${column.bold ? '700' : '400'} 13px Arial, sans-serif`;
                        context.fillText(String(row[column.key] ?? '-'), cellX + 10, y + (rowHeight / 2), column.width - 16);
                        context.strokeStyle = '#cbd5e1';
                        context.strokeRect(cellX, y, column.width, rowHeight);
                        cellX += column.width;
                    });
                });

                const image = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));

                if (!image) {
                    return;
                }

                const downloadImage = () => {
                    const url = URL.createObjectURL(image);
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = `registros-${new Date().toISOString().slice(0, 10)}.png`;
                    link.click();
                    URL.revokeObjectURL(url);
                };

                try {
                    if (!navigator.clipboard?.write || !window.ClipboardItem) {
                        throw new Error('A cópia de imagem não está disponível neste navegador.');
                    }

                    await navigator.clipboard.write([new ClipboardItem({ 'image/png': image })]);
                    window.dispatchEvent(new CustomEvent('registers-image-share-status', {
                        detail: { message: 'Imagem copiada. Cole no WhatsApp para compartilhar.' },
                    }));
                } catch {
                    downloadImage();
                    window.dispatchEvent(new CustomEvent('registers-image-share-status', {
                        detail: { message: 'Imagem baixada. Anexe o arquivo para compartilhar.' },
                    }));
                }
            });

            window.addEventListener('registers-image-share-status', (event) => {
                let notice = document.getElementById('registers-image-share-status');

                if (!notice) {
                    notice = document.createElement('div');
                    notice.id = 'registers-image-share-status';
                    notice.className = 'fixed bottom-6 left-1/2 z-[100] -translate-x-1/2 rounded-lg bg-gray-900 px-4 py-3 text-sm font-medium text-white shadow-lg';
                    document.body.append(notice);
                }

                notice.textContent = event.detail.message;
                notice.classList.remove('hidden');
                window.clearTimeout(notice.hideTimeout);
                notice.hideTimeout = window.setTimeout(() => notice.classList.add('hidden'), 4500);
            });
        </script>
    @endonce
</x-filament-panels::page>
