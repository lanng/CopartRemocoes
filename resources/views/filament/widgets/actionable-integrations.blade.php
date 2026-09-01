<x-filament-widgets::widget class="fi-wi-table fi-wi-compact-integrations">
    <style>
        .fi-wi-compact-integrations .fi-ta-row td,
        .fi-wi-compact-integrations .fi-ta-row td > div,
        .fi-wi-compact-integrations .fi-ta-row .fi-ta-col-wrp,
        .fi-wi-compact-integrations .fi-ta-row [class*='py-'] {
            padding-top: 0.25rem !important;
            padding-bottom: 0.25rem !important;
        }

        .fi-wi-compact-integrations .fi-ta-actions {
            gap: 0.375rem;
        }

        .fi-wi-compact-integrations .fi-badge {
            padding-inline: 0.5rem;
            font-size: 0.65rem;
            line-height: 1rem;
        }
    </style>

    {{ $this->table }}

    <div class="flex justify-end border-t border-gray-200 px-6 py-3 dark:border-white/10">
        <a
            href="{{ route('filament.admin.resources.integration-inbox-items.index') }}"
            class="text-sm font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
        >
            Ver todas as integrações
        </a>
    </div>
</x-filament-widgets::widget>
