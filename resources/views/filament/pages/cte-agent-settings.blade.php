<x-filament-panels::page>
    @php
        $agent = $this->getAgent();
        $token = $this->getLatestToken();
    @endphp

    <div class="flex flex-col gap-6">
        <x-filament::section heading="Configuração">
            <form wire:submit="save" class="flex flex-col gap-6">
                {{ $this->form }}

                <div>
                    <x-filament::button type="submit">
                        Salvar configuração
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <x-filament::section heading="Status da conexão">
            <dl class="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3">
                <div>
                    <dt class="text-sm font-medium text-gray-500">Status</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $agent ? ($agent->is_active ? 'Ativo' : 'Inativo') : 'Não configurado' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Hostname</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $agent?->hostname ?: 'Não informado' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Modo</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $agent ? ($agent->is_dry_run ? 'Simulação' : 'Emissão real') : 'Não configurado' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Versão</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $agent?->version ?: 'Ainda não reportada' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Último heartbeat</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $agent?->last_seen_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? 'Ainda não recebido' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Token</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $token ? "ID {$token->id} ••••••••" : 'Nenhum token configurado' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Token criado em</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $token?->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? 'Não informado' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Último uso do token</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $token?->last_used_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? 'Ainda não utilizado' }}
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        @if ($generatedToken)
            <x-filament::section heading="Token gerado" icon="heroicon-o-key">
                <div
                    class="flex flex-col gap-4"
                    x-data="{ token: @js($generatedToken) }"
                >
                    <p class="text-sm text-warning-700 dark:text-warning-400">
                        Copie agora. Por segurança, este token não será exibido novamente após recarregar a página.
                    </p>

                    <div class="flex flex-col gap-3 sm:flex-row">
                        <input
                            type="text"
                            readonly
                            value="{{ $generatedToken }}"
                            class="fi-input block w-full rounded-lg border-gray-300 bg-gray-50 font-mono text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
                        >
                        <x-filament::button
                            type="button"
                            color="gray"
                            x-on:click="navigator.clipboard.writeText(token)"
                        >
                            Copiar token
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
