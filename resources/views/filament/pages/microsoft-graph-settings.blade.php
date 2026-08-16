<x-filament-panels::page>
    @php($connection = \App\Models\MicrosoftGraphConnection::query()->first())

    <x-filament::section heading="Conexão Microsoft Graph">
        @if ($connection?->is_active)
            <div class="flex flex-col gap-2">
                <p>Conta conectada: <strong>{{ $connection->account_email }}</strong></p>
                <p>Última sincronização: {{ $connection->last_synced_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? 'Ainda não sincronizado' }}</p>
                @if ($connection->last_error)
                    <p class="text-danger-600">Último erro: {{ $connection->last_error }}</p>
                @endif
            </div>
        @else
            <div class="flex flex-col gap-4">
                <p>Nenhuma conta Outlook está conectada.</p>
                <x-filament::button tag="a" href="{{ url('/microsoft/graph/connect') }}">
                    Conectar Outlook
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
