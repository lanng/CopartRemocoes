@props(['column', 'label', 'compact' => false])

@php
    $colorAttribute = \App\Filament\Resources\RegisterResource::spreadsheetColorAttributeForColumn($column);
    $filterTitle = match ($column) {
        'vehicle_plate' => 'Filtrar placa',
        'vehicle_model' => 'Filtrar modelo',
        'origin_city' => 'Filtrar origem',
        'destination_city' => 'Filtrar destino',
        'deadline_withdraw' => 'Filtrar coleta',
        'deadline_delivery' => 'Filtrar entrega',
        'status' => 'Filtrar situação',
        'tow_yard' => 'Filtrar local',
        'condition' => 'Filtrar condição',
        'value' => 'Filtrar valor',
        default => "Filtrar {$label}",
    };
    $activeExpression = match ($column) {
        'deadline_withdraw' => '($wire.columnFilters.deadline_withdraw_color || $wire.columnFilters.deadline_withdraw?.from || $wire.columnFilters.deadline_withdraw?.until)',
        'deadline_delivery' => '($wire.columnFilters.deadline_delivery_color || $wire.columnFilters.deadline_delivery?.from || $wire.columnFilters.deadline_delivery?.until)',
        default => match (true) {
            $colorAttribute !== null => '$wire.columnFilters.'.$colorAttribute,
            $column === 'value' => '($wire.columnFilters.value?.minimum != null || $wire.columnFilters.value?.maximum != null)',
            in_array($column, ['status', 'tow_yard'], true) => '$wire.columnFilters.'.$column,
            default => 'false',
        },
    };
@endphp

<div
    class="shrink-0"
    x-data="{
        open: false,
        toggleMenu() {
            this.open = ! this.open;

            if (this.open) {
                this.$nextTick(() => this.positionMenu());
            }
        },
        positionMenu() {
            if (! this.open) {
                return;
            }

            const trigger = this.$refs.trigger.getBoundingClientRect();
            const menu = this.$refs.menu;
            const left = Math.max(8, Math.min(trigger.left, window.innerWidth - menu.offsetWidth - 8));
            const below = trigger.bottom + menu.offsetHeight + 8 <= window.innerHeight;
            const top = below
                ? trigger.bottom + 6
                : Math.max(8, trigger.top - menu.offsetHeight - 6);

            menu.style.left = `${left}px`;
            menu.style.top = `${top}px`;
        },
    }"
    x-on:click.outside="open = false"
    x-on:keydown.escape.stop.prevent="open = false"
    x-on:resize.window="positionMenu()"
    x-on:scroll.window.capture="open = false"
>
    <button
        type="button"
        x-ref="trigger"
        x-on:click.stop="toggleMenu()"
        x-bind:class="{ 'text-primary-600 bg-primary-50 dark:bg-primary-400/10': open || Boolean({!! $activeExpression !!}) }"
        x-bind:aria-pressed="Boolean({!! $activeExpression !!})"
        aria-label="{{ $filterTitle }}"
        title="{{ $filterTitle }}"
        data-register-column-filter="{{ $column }}"
        @class([
            'relative inline-flex items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-500 dark:hover:bg-white/10 dark:hover:text-gray-200',
            'h-4 w-4' => $compact,
            'h-5 w-5' => ! $compact,
        ])
    >
        <x-filament::icon icon="heroicon-m-funnel" @class(['h-2.5 w-2.5' => $compact, 'h-3 w-3' => ! $compact]) />
        <span
            x-show="Boolean({!! $activeExpression !!})"
            x-cloak
            class="absolute -right-0.5 -top-0.5 h-2 w-2 rounded-full bg-primary-600 ring-2 ring-white dark:ring-gray-900"
        ></span>
    </button>

    <div
        x-ref="menu"
        x-cloak
        x-show="open"
        x-transition.opacity.duration.100ms
        x-on:click.stop
        class="registers-column-filter-menu fixed z-50 rounded-xl bg-white p-4 text-start shadow-xl ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10"
        role="dialog"
        aria-label="{{ $filterTitle }}"
        style="display: none; width: 16rem;"
    >
        <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">{{ $filterTitle }}</h3>

        @if ($colorAttribute !== null)
            <label class="registers-column-filter-field block space-y-1.5">
                <span class="registers-column-filter-label text-xs font-medium text-gray-600 dark:text-gray-300">Cor</span>
                <select
                    wire:model.live="columnFilters.{{ $colorAttribute }}"
                    class="registers-column-filter-control w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-950 dark:text-white"
                >
                    <option value="">Todas as cores</option>
                    @foreach (\App\Filament\Resources\RegisterResource::spreadsheetColumnColorOptions($column) as $value => $colorLabel)
                        <option value="{{ $value }}">{{ $colorLabel }}</option>
                    @endforeach
                </select>
            </label>
            @if (in_array($column, ['deadline_withdraw', 'deadline_delivery'], true))
                <div class="mt-3 space-y-3">
                    <label class="registers-column-filter-field block space-y-1.5">
                        <span class="registers-column-filter-label text-xs font-medium text-gray-600 dark:text-gray-300">A partir de</span>
                        <input
                            type="date"
                            wire:model.live="columnFilters.{{ $column }}.from"
                            class="registers-column-filter-control w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-950 dark:text-white"
                        />
                    </label>
                    <label class="registers-column-filter-field block space-y-1.5">
                        <span class="registers-column-filter-label text-xs font-medium text-gray-600 dark:text-gray-300">Até</span>
                        <input
                            type="date"
                            wire:model.live="columnFilters.{{ $column }}.until"
                            class="registers-column-filter-control w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-950 dark:text-white"
                        />
                    </label>
                </div>
            @endif
        @elseif ($column === 'status')
            <label class="registers-column-filter-field block space-y-1.5">
                <span class="registers-column-filter-label text-xs font-medium text-gray-600 dark:text-gray-300">Situação</span>
                <select
                    wire:model.live="columnFilters.status"
                    class="registers-column-filter-control w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-950 dark:text-white"
                >
                    <option value="">Todas as situações</option>
                    @foreach (\App\Enums\RegisterStatusEnum::optionsWithLabels() as $value => $statusLabel)
                        <option value="{{ $value }}">{{ $statusLabel }}</option>
                    @endforeach
                </select>
            </label>
        @elseif ($column === 'tow_yard')
            <label class="registers-column-filter-field block space-y-1.5">
                <span class="registers-column-filter-label text-xs font-medium text-gray-600 dark:text-gray-300">Local</span>
                <select
                    wire:model.live="columnFilters.tow_yard"
                    class="registers-column-filter-control w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-950 dark:text-white"
                >
                    <option value="">Todos os locais</option>
                    @foreach (\App\Filament\Resources\RegisterResource::spreadsheetTowYardFilterOptions() as $towYard)
                        <option value="{{ $towYard }}">{{ $towYard }}</option>
                    @endforeach
                </select>
            </label>
        @elseif ($column === 'value')
            <div class="space-y-3">
                <label class="registers-column-filter-field block space-y-1.5">
                    <span class="registers-column-filter-label text-xs font-medium text-gray-600 dark:text-gray-300">Valor mínimo</span>
                    <input
                        type="number"
                        min="0"
                        step="0.01"
                        wire:model.live.debounce.400ms="columnFilters.value.minimum"
                        class="registers-column-filter-control w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-950 dark:text-white"
                    />
                </label>
                <label class="registers-column-filter-field block space-y-1.5">
                    <span class="registers-column-filter-label text-xs font-medium text-gray-600 dark:text-gray-300">Valor máximo</span>
                    <input
                        type="number"
                        min="0"
                        step="0.01"
                        wire:model.live.debounce.400ms="columnFilters.value.maximum"
                        class="registers-column-filter-control w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-950 dark:text-white"
                    />
                </label>
            </div>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">Este campo ainda não possui dados no sistema.</p>
        @endif

        @if ($column !== 'condition')
            <button
                type="button"
                wire:click="clearColumnFilter('{{ $column }}')"
                class="mt-4 text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
            >
                Limpar filtro
            </button>
        @endif
    </div>
</div>
