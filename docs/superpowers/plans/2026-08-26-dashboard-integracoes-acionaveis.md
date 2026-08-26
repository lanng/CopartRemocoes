# Dashboard de Integracoes Acionaveis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Distinguir baixas de entrega de inclusoes de registro e exibir na home uma lista priorizada das integracoes que exigem acao humana.

**Architecture:** `IntegrationInboxItem` sera a fonte unica da classificacao e da prioridade, por meio de metodos semanticos e scopes Eloquent. O reconhecimento de alertas de entrega usara campos proprios para preservar a data original de processamento. Um table widget Filament reutilizara os servicos existentes para agir sobre os mesmos itens exibidos na caixa completa.

**Tech Stack:** PHP 8.3, Laravel 12, Eloquent, Filament 3, Livewire 3, PHPUnit 11, MySQL.

---

## Estrutura de arquivos

### Criar

- `database/migrations/2026_08_26_120000_add_acknowledgement_to_integration_inbox_items_table.php`: usuario e instante de reconhecimento.
- `app/Services/MicrosoftGraph/AcknowledgeIntegrationAlert.php`: reconhecimento transacional de alertas de entrega.
- `app/Filament/Widgets/ActionableIntegrations.php`: consulta, colunas e acoes do widget.
- `app/Filament/Support/IntegrationInboxItemPresentation.php`: opcoes de registro compartilhadas entre resource e widget.
- `resources/views/filament/widgets/actionable-integrations.blade.php`: tabela e link de rodape.
- `tests/Feature/Services/AcknowledgeIntegrationAlertTest.php`: contrato do reconhecimento.
- `tests/Feature/Filament/ActionableIntegrationsTest.php`: classificacao, prioridade, limite e acoes do widget.

### Modificar

- `app/Models/IntegrationInboxItem.php`: campos, relacionamentos, rotulos, classificacao e scopes.
- `database/factories/IntegrationInboxItemFactory.php`: defaults dos novos campos.
- `app/Filament/Resources/IntegrationInboxItemResource.php`: nomenclatura, coluna de tipo e reconhecimento de entrega.
- `app/Providers/Filament/AdminPanelProvider.php`: registrar o widget e remover widgets padrao sem utilidade operacional.
- `app/Console/Commands/CleanupIntegrationInboxItems.php`: preservar alertas de entrega ate o reconhecimento.
- `tests/Feature/Models/IntegrationInboxItemTest.php`: persistencia e semantica acionavel.
- `tests/Feature/Filament/IntegrationInboxItemResourceTest.php`: rotulos, badges e acao de reconhecimento.

### Task 1: Persistir o reconhecimento sem perder a resolucao

**Files:**
- Create: `database/migrations/2026_08_26_120000_add_acknowledgement_to_integration_inbox_items_table.php`
- Modify: `app/Models/IntegrationInboxItem.php`
- Modify: `database/factories/IntegrationInboxItemFactory.php`
- Test: `tests/Feature/Models/IntegrationInboxItemTest.php`

- [ ] **Step 1: Gerar a migration**

Run:

```bash
php artisan make:migration add_acknowledgement_to_integration_inbox_items_table --table=integration_inbox_items --no-interaction
```

Renomear o arquivo gerado para `database/migrations/2026_08_26_120000_add_acknowledgement_to_integration_inbox_items_table.php`.

- [ ] **Step 2: Escrever o teste RED de persistencia**

Adicionar a `tests/Feature/Models/IntegrationInboxItemTest.php`:

```php
public function test_it_persists_alert_acknowledgement_separately_from_resolution(): void
{
    $user = User::factory()->create();
    $item = IntegrationInboxItem::factory()->create([
        'status' => 'processed',
        'delivery_alert' => 'missing_authorized_cte',
        'resolved_at' => '2026-08-19 12:00:00',
        'acknowledged_by' => $user->id,
        'acknowledged_at' => '2026-08-20 13:00:00',
    ])->refresh();

    $this->assertSame('2026-08-19 12:00:00', $item->resolved_at->format('Y-m-d H:i:s'));
    $this->assertSame('2026-08-20 13:00:00', $item->acknowledged_at->format('Y-m-d H:i:s'));
    $this->assertTrue($item->acknowledger->is($user));
}
```

- [ ] **Step 3: Confirmar RED**

Run:

```bash
php artisan test --compact tests/Feature/Models/IntegrationInboxItemTest.php --filter=acknowledgement
```

Expected: FAIL por colunas, cast e relacionamento ausentes.

- [ ] **Step 4: Implementar migration, model e factory**

Na migration:

```php
Schema::table('integration_inbox_items', function (Blueprint $table) {
    $table->foreignId('acknowledged_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
    $table->timestamp('acknowledged_at')->nullable()->after('acknowledged_by')->index();
});
```

No `down()`, remover a foreign key, o indice de `acknowledged_at` e as duas colunas. Em `IntegrationInboxItem`, adicionar os campos ao `$fillable`, o cast `'acknowledged_at' => 'datetime'` e:

```php
public function acknowledger(): BelongsTo
{
    return $this->belongsTo(User::class, 'acknowledged_by');
}
```

Na factory, definir `acknowledged_by` e `acknowledged_at` como `null`.

- [ ] **Step 5: Confirmar GREEN e formatar**

Run:

```bash
php artisan test --compact tests/Feature/Models/IntegrationInboxItemTest.php --filter=acknowledgement
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commitar a task**

```bash
git add database/migrations/2026_08_26_120000_add_acknowledgement_to_integration_inbox_items_table.php app/Models/IntegrationInboxItem.php database/factories/IntegrationInboxItemFactory.php tests/Feature/Models/IntegrationInboxItemTest.php
git commit -m "feat(integration): persist alert acknowledgement"
```

### Task 2: Centralizar classificacao e prioridade acionavel

**Files:**
- Modify: `app/Models/IntegrationInboxItem.php`
- Test: `tests/Feature/Models/IntegrationInboxItemTest.php`

- [ ] **Step 1: Escrever a matriz RED de itens acionaveis**

Criar um data provider com estes casos:

```php
return [
    'checklist pending' => [['message_type' => 'checklist', 'status' => 'pending'], true],
    'missing CT-e' => [['message_type' => 'checklist', 'status' => 'processed', 'delivery_alert' => 'missing_authorized_cte'], true],
    'unexpected flow' => [['message_type' => 'checklist', 'status' => 'processed', 'delivery_alert' => 'unexpected_status'], true],
    'removal processing error' => [['message_type' => 'removal_request', 'status' => 'pending', 'failure_reason' => 'domain_error'], true],
    'removal review' => [['message_type' => 'removal_request', 'status' => 'pending', 'proposed_changes' => ['value' => ['current' => '1.00', 'proposed' => '2.00']]], true],
    'removal alert' => [['message_type' => 'removal_request', 'status' => 'alert', 'alerts' => ['zero_fipe']], true],
    'acknowledged delivery' => [['message_type' => 'checklist', 'status' => 'processed', 'delivery_alert' => 'unexpected_status', 'acknowledged_at' => now()], false],
    'processed removal' => [['message_type' => 'removal_request', 'status' => 'processed'], false],
];
```

Para cada caso, persistir o item, verificar `requiresUserAction()` e verificar se `IntegrationInboxItem::query()->requiringUserAction()` inclui ou exclui o ID.

- [ ] **Step 2: Escrever o teste RED de prioridade**

Criar um item de cada prioridade em ordem de criacao embaralhada. Executar:

```php
$ordered = IntegrationInboxItem::query()
    ->requiringUserAction()
    ->byActionPriority()
    ->pluck('id')
    ->all();
```

Esperar: entrega sem CT-e, inclusao com erro, fluxo inesperado, pendencia e alerta de FIPE/frete. Adicionar dois itens da mesma prioridade e provar que o `received_at` mais antigo vem primeiro.

- [ ] **Step 3: Confirmar RED**

Run:

```bash
php artisan test --compact tests/Feature/Models/IntegrationInboxItemTest.php --filter='user_action|priority'
```

Expected: FAIL por metodos e scopes ausentes.

- [ ] **Step 4: Implementar metodos e scopes**

Adicionar ao model:

```php
public function requiresUserAction(): bool
{
    if ($this->acknowledged_at !== null) {
        return false;
    }

    if ($this->message_type === 'checklist') {
        return $this->status === 'pending' || $this->delivery_alert !== null;
    }

    return $this->message_type === 'removal_request'
        && in_array($this->status, ['pending', 'alert'], true)
        && $this->resolved_at === null;
}

public function scopeRequiringUserAction(Builder $query): Builder
{
    return $query
        ->whereNull('acknowledged_at')
        ->where(function (Builder $query): void {
            $query
                ->where(function (Builder $query): void {
                    $query->where('message_type', 'checklist')
                        ->where(function (Builder $query): void {
                            $query->where('status', 'pending')->orWhereNotNull('delivery_alert');
                        });
                })
                ->orWhere(function (Builder $query): void {
                    $query->where('message_type', 'removal_request')
                        ->whereIn('status', ['pending', 'alert'])
                        ->whereNull('resolved_at');
                });
        });
}
```

Adicionar `scopeByActionPriority()` com a ordem aprovada e desempate por recebimento:

```php
public function scopeByActionPriority(Builder $query): Builder
{
    return $query
        ->orderByRaw("CASE
            WHEN delivery_alert = 'missing_authorized_cte' THEN 1
            WHEN message_type = 'removal_request'
                AND failure_reason IN ('domain_error', 'processing_failed', 'graph_connection_missing') THEN 2
            WHEN delivery_alert = 'unexpected_status' THEN 3
            WHEN status = 'pending' THEN 4
            WHEN status = 'alert' THEN 5
            ELSE 6
        END")
        ->orderBy('received_at')
        ->orderBy('id');
}
```

Adicionar `actionPriority(): int` com a mesma matriz para testes unitarios e apresentacao.

- [ ] **Step 5: Implementar rotulos operacionais**

Atualizar `messageTypeLabel()` para retornar `Baixa de entrega` e `Inclusao de registro`. Adicionar `actionLabel()` para retornar a ocorrencia humana prioritaria (`Entrega sem CT-e`, `Falha na importacao`, `Entrega fora do fluxo`, motivo pendente ou alerta de inclusao).

- [ ] **Step 6: Confirmar GREEN**

Run:

```bash
php artisan test --compact tests/Feature/Models/IntegrationInboxItemTest.php
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commitar a task**

```bash
git add app/Models/IntegrationInboxItem.php tests/Feature/Models/IntegrationInboxItemTest.php
git commit -m "feat(integration): classify actionable inbox items"
```

### Task 3: Reconhecer alertas de entrega

**Files:**
- Create: `app/Services/MicrosoftGraph/AcknowledgeIntegrationAlert.php`
- Create: `tests/Feature/Services/AcknowledgeIntegrationAlertTest.php`
- Modify: `app/Console/Commands/CleanupIntegrationInboxItems.php`
- Modify: `tests/Feature/Console/CleanupIntegrationInboxItemsTest.php`

- [ ] **Step 1: Gerar servico e teste**

Run:

```bash
php artisan make:class Services/MicrosoftGraph/AcknowledgeIntegrationAlert --no-interaction
php artisan make:test --phpunit Services/AcknowledgeIntegrationAlertTest --no-interaction
```

- [ ] **Step 2: Escrever testes RED**

Cobrir o caminho feliz:

```php
$result = app(AcknowledgeIntegrationAlert::class)->handle($item, $user);

$this->assertSame($user->id, $result->acknowledged_by);
$this->assertNotNull($result->acknowledged_at);
$this->assertSame('processed', $result->status);
$this->assertSame('missing_authorized_cte', $result->delivery_alert);
$this->assertSame($originalResolvedAt, $result->resolved_at->toDateTimeString());
```

Cobrir rejeicao de item sem `delivery_alert`, pedido de remocao e alerta ja reconhecido. Esperar `DomainException` e nenhuma mutacao nesses casos.

- [ ] **Step 3: Confirmar RED**

Run:

```bash
php artisan test --compact tests/Feature/Services/AcknowledgeIntegrationAlertTest.php
```

Expected: FAIL porque o servico ainda nao implementa o contrato.

- [ ] **Step 4: Implementar reconhecimento transacional**

Dentro de `DB::transaction()`, buscar o item com `lockForUpdate()`, validar `message_type === 'checklist'`, `delivery_alert !== null` e `acknowledged_at === null`, e persistir somente:

```php
[
    'acknowledged_by' => $user->id,
    'acknowledged_at' => now(),
]
```

Nao alterar `status`, `delivery_alert`, `resolved_by` ou `resolved_at`.

- [ ] **Step 5: Confirmar GREEN e formatar**

Antes da verificacao, adicionar a `CleanupIntegrationInboxItemsTest` um alerta de entrega processado e antigo sem `acknowledged_at`, executar o comando e provar que ele permanece. Adicionar outro alerta reconhecido ha mais de 30 dias e provar que ele pode ser removido. Ajustar o comando para excluir da limpeza itens com `delivery_alert` nao nulo e `acknowledged_at` nulo.

Run:

```bash
php artisan test --compact tests/Feature/Services/AcknowledgeIntegrationAlertTest.php tests/Feature/Console/CleanupIntegrationInboxItemsTest.php
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commitar a task**

```bash
git add app/Services/MicrosoftGraph/AcknowledgeIntegrationAlert.php app/Console/Commands/CleanupIntegrationInboxItems.php tests/Feature/Services/AcknowledgeIntegrationAlertTest.php tests/Feature/Console/CleanupIntegrationInboxItemsTest.php
git commit -m "feat(integration): acknowledge delivery alerts"
```

### Task 4: Distinguir os fluxos na caixa completa

**Files:**
- Modify: `app/Filament/Resources/IntegrationInboxItemResource.php`
- Test: `tests/Feature/Filament/IntegrationInboxItemResourceTest.php`

- [ ] **Step 1: Escrever testes RED de nomenclatura e tipo**

Verificar:

```php
$this->assertSame('Integrações por e-mail', IntegrationInboxItemResource::getNavigationLabel());
$this->assertSame('Integração por e-mail', IntegrationInboxItemResource::getModelLabel());

$table = Livewire::test(ListIntegrationInboxItems::class)->instance()->getTable();
$this->assertSame(
    ['status', 'message_type', 'extracted_vehicle_id', 'received_at', 'occurrence'],
    array_keys($table->getColumns()),
);
```

Criar um checklist e uma inclusao, renderizar a listagem e verificar `Baixa de entrega` e `Inclusao de registro` como badges.

- [ ] **Step 2: Escrever o teste RED da acao de reconhecimento**

Criar um checklist processado com `delivery_alert`, obter `acknowledgeDeliveryAlert`, verificar visibilidade e executar com `callTableAction()`. Assertar `acknowledged_by` e `acknowledged_at`. Verificar que a acao nao aparece para inclusoes ou alertas reconhecidos.

- [ ] **Step 3: Confirmar RED**

Run:

```bash
php artisan test --compact tests/Feature/Filament/IntegrationInboxItemResourceTest.php --filter='type|acknowledge_delivery|translated'
```

- [ ] **Step 4: Atualizar recurso e coluna**

Alterar os labels estaticos para `Integracoes por e-mail` / `Integracao por e-mail`. Inserir depois de `status`:

```php
Tables\Columns\TextColumn::make('message_type')
    ->label('Tipo')
    ->formatStateUsing(fn (IntegrationInboxItem $record): string => $record->messageTypeLabel())
    ->badge()
    ->color(fn (IntegrationInboxItem $record): string => $record->isRemovalRequest() ? 'info' : 'gray');
```

- [ ] **Step 5: Adicionar reconhecimento de entrega**

Adicionar `acknowledgeDeliveryAlert`, visivel quando o item e checklist, possui `delivery_alert` e ainda nao possui `acknowledged_at`. Exigir confirmacao e chamar `AcknowledgeIntegrationAlert::handle($record, auth()->user())`.

Adicionar ao infolist `acknowledger.name` e `acknowledged_at`, ambos com placeholder `Nao reconhecido`.

- [ ] **Step 6: Confirmar GREEN e formatar**

Run:

```bash
php artisan test --compact tests/Feature/Filament/IntegrationInboxItemResourceTest.php
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commitar a task**

```bash
git add app/Filament/Resources/IntegrationInboxItemResource.php tests/Feature/Filament/IntegrationInboxItemResourceTest.php
git commit -m "feat(filament): distinguish email integration types"
```

### Task 5: Criar o widget de acoes necessarias

**Files:**
- Create: `app/Filament/Widgets/ActionableIntegrations.php`
- Create: `app/Filament/Support/IntegrationInboxItemPresentation.php`
- Create: `resources/views/filament/widgets/actionable-integrations.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Create: `tests/Feature/Filament/ActionableIntegrationsTest.php`

- [ ] **Step 1: Gerar o widget de tabela**

Run:

```bash
php artisan make:filament-widget ActionableIntegrations --table --panel=admin --no-interaction
php artisan make:class Filament/Support/IntegrationInboxItemPresentation --no-interaction
php artisan make:test --phpunit Filament/ActionableIntegrationsTest --no-interaction
```

- [ ] **Step 2: Escrever testes RED de consulta, limite e ordem**

Autenticar um usuario, configurar o painel admin e criar 12 itens acionaveis e 2 resolvidos. Em `Livewire::test(ActionableIntegrations::class)` verificar:

```php
->assertSuccessful()
->assertCountTableRecords(10)
->assertCanSeeTableRecords($expectedFirstTen, inOrder: true)
->assertCanNotSeeTableRecords($resolvedItems);
```

Os dados devem conter todas as cinco prioridades e dois itens da mesma prioridade com datas diferentes.

- [ ] **Step 3: Escrever testes RED de apresentacao**

Verificar as colunas `message_type`, `extracted_vehicle_id`, `action_label` e `received_at`, o heading `Acoes necessarias`, o estado vazio `Nenhuma acao pendente` e o texto/link `Ver todas as integracoes` apontando para `IntegrationInboxItemResource::getUrl('index')`. Inspecionar `$widget->instance()->getTable()->getQuery()->getEagerLoads()` e exigir as chaves `register`, `resolver` e `acknowledger`, impedindo N+1 ao renderizar as linhas.

- [ ] **Step 4: Escrever testes RED das acoes**

Verificar visibilidade e execucao de:

- `acknowledgeDeliveryAlert` para checklist com alerta;
- `retryRemovalRequest` para `domain_error`, `processing_failed` e `graph_connection_missing`;
- `acknowledgeRemovalAlert` para inclusao em `alert`;
- `view` para qualquer item;
- `resolveChecklist` para checklist pendente quando houver registro compativel;
- `reviewRemovalRequest` para inclusao com propostas ou PDF candidato.

As acoes devem chamar os servicos existentes e persistir o mesmo resultado ja coberto pela resource completa.

- [ ] **Step 5: Confirmar RED**

Run:

```bash
php artisan test --compact tests/Feature/Filament/ActionableIntegrationsTest.php
```

Expected: FAIL porque widget, consulta e view ainda nao existem.

- [ ] **Step 6: Implementar tabela e layout**

Em `ActionableIntegrations`:

```php
protected int|string|array $columnSpan = 'full';

protected static string $view = 'filament.widgets.actionable-integrations';

public function table(Table $table): Table
{
    return $table
    ->heading('Ações necessárias')
        ->query(
            IntegrationInboxItem::query()
                ->requiringUserAction()
                ->byActionPriority()
                ->with(['register', 'resolver', 'acknowledger'])
                ->limit(10)
        )
        ->paginated(false)
        ->columns([
            TextColumn::make('message_type')
                ->label('Tipo')
                ->formatStateUsing(fn (IntegrationInboxItem $record): string => $record->messageTypeLabel())
                ->badge()
                ->color(fn (IntegrationInboxItem $record): string => $record->isRemovalRequest() ? 'info' : 'gray'),
            TextColumn::make('extracted_vehicle_id')
                ->label('Veículo')
                ->description(fn (IntegrationInboxItem $record): ?string => $record->extracted_vehicle_plate),
            TextColumn::make('action_label')
                ->label('Ocorrência')
                ->state(fn (IntegrationInboxItem $record): string => $record->actionLabel())
                ->badge()
                ->wrap(),
            TextColumn::make('received_at')
                ->label('Recebido')
                ->dateTime('d/m/Y H:i')
                ->timezone('America/Sao_Paulo'),
        ])
        ->emptyStateHeading('Nenhuma ação pendente')
        ->emptyStateDescription('As integrações que exigirem intervenção aparecerão aqui.');
}
```

Usar badges de tipo, veiculo com placa na descricao, `actionLabel()` como ocorrencia e data/hora em Sao Paulo.

- [ ] **Step 7: Implementar acoes reutilizando servicos**

Configurar as seis acoes testadas no Step 4 com estas condicoes:

```php
ViewAction::make()->url(fn (IntegrationInboxItem $record): string => IntegrationInboxItemResource::getUrl('view', ['record' => $record]));

Action::make('acknowledgeDeliveryAlert')
    ->visible(fn (IntegrationInboxItem $record): bool => ! $record->isRemovalRequest()
        && $record->delivery_alert !== null
        && $record->acknowledged_at === null);

Action::make('retryRemovalRequest')
    ->visible(fn (IntegrationInboxItem $record): bool => $record->isRemovalRequest()
        && $record->status === 'pending'
        && in_array($record->failure_reason, ['domain_error', 'processing_failed', 'graph_connection_missing'], true));

Action::make('acknowledgeRemovalAlert')
    ->visible(fn (IntegrationInboxItem $record): bool => $record->isRemovalRequest() && $record->status === 'alert');

Action::make('resolveChecklist')
    ->visible(fn (IntegrationInboxItem $record): bool => ! $record->isRemovalRequest() && $record->status === 'pending');

Action::make('reviewRemovalRequest')
    ->visible(fn (IntegrationInboxItem $record): bool => $record->isRemovalRequest()
        && $record->status === 'pending'
        && ($record->proposed_changes !== null || $record->candidate_pdf_path !== null));
```

Cada callback deve chamar, respectivamente, `AcknowledgeIntegrationAlert`, `RetryRemovalRequestImport`, `ResolveRemovalRequestImport::acknowledge`, `ResolveIntegrationInboxItem` ou `ResolveRemovalRequestImport::apply`. Nao duplicar mutacoes Eloquent no widget. Extrair a montagem de opcoes de conciliacao da resource para `app/Filament/Support/IntegrationInboxItemPresentation.php`, com `matchingRegisterOptions(IntegrationInboxItem $item): array`, e usar a classe na resource e no widget.

- [ ] **Step 8: Implementar view e registro no painel**

Em `resources/views/filament/widgets/actionable-integrations.blade.php`:

```blade
<x-filament-widgets::widget>
    {{ $this->table }}

    <div class="flex justify-end border-t border-gray-200 px-6 py-3 dark:border-white/10">
        <a class="text-sm font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400"
           href="{{ \App\Filament\Resources\IntegrationInboxItemResource::getUrl('index') }}">
            Ver todas as integrações
        </a>
    </div>
</x-filament-widgets::widget>
```

No `AdminPanelProvider`, manter `discoverWidgets()` para registrar `ActionableIntegrations` automaticamente e remover `AccountWidget` e `FilamentInfoWidget` da chamada `widgets()`, deixando a home exclusivamente operacional.

- [ ] **Step 9: Confirmar GREEN e responsividade**

Run:

```bash
php artisan test --compact tests/Feature/Filament/ActionableIntegrationsTest.php
vendor/bin/pint --dirty --format agent
```

Abrir `/admin` em desktop e viewport movel. Confirmar coluna de tipo, quebra de texto, acoes, tema claro/escuro e ausencia de scroll horizontal causado pelo widget.

- [ ] **Step 10: Commitar a task**

```bash
git add app/Filament/Widgets/ActionableIntegrations.php app/Filament/Support/IntegrationInboxItemPresentation.php resources/views/filament/widgets/actionable-integrations.blade.php app/Providers/Filament/AdminPanelProvider.php tests/Feature/Filament/ActionableIntegrationsTest.php
git commit -m "feat(filament): add actionable integrations dashboard"
```

### Task 6: Verificacao integrada

**Files:**
- Modify: `docs/superpowers/plans/2026-08-26-dashboard-integracoes-acionaveis.md`

- [ ] **Step 1: Rodar testes focados**

Run:

```bash
php artisan test --compact tests/Feature/Models/IntegrationInboxItemTest.php tests/Feature/Services/AcknowledgeIntegrationAlertTest.php tests/Feature/Console/CleanupIntegrationInboxItemsTest.php tests/Feature/Filament/IntegrationInboxItemResourceTest.php tests/Feature/Filament/ActionableIntegrationsTest.php
```

Expected: todos PASS.

- [ ] **Step 2: Rodar formatacao e suite completa**

Run:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
git diff --check
```

Expected: Pint sem alteracoes pendentes, suite completa PASS e diff sem whitespace invalido.

- [ ] **Step 3: Verificar migration e painel**

Run:

```bash
php artisan migrate --pretend
php artisan route:list --path=admin
```

No ambiente local de testes, executar `php artisan migrate` e validar `/admin` e `/admin/integration-inbox-items` com dados reais.

- [ ] **Step 4: Atualizar checklist e commit final**

Marcar todos os passos concluidos neste plano e executar:

```bash
git add -f docs/superpowers/plans/2026-08-26-dashboard-integracoes-acionaveis.md
git commit -m "test(filament): verify actionable integrations dashboard"
```
