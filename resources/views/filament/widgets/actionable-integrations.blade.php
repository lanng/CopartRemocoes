<x-filament-widgets::widget class="fi-wi-table">
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
