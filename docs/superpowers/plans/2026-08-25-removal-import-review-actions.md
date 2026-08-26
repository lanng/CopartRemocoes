# Acoes de Revisao de Importacoes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir aceitar, revisar, rejeitar e reprocessar pedidos de remocao pendentes, mantendo a conciliacao de baixas segura quando nenhum registro existir.

**Architecture:** Um servico pequeno controlara a transicao de um pedido de remocao pendente para `queued` e despachara o job existente. O recurso Filament adicionara acoes explicitas para aceite total e retry, enquanto o servico `ResolveRemovalRequestImport` continuara concentrando a aplicacao transacional de alteracoes. A acao de baixa sera desabilitada quando a busca nao encontrar um registro compativel.

**Tech Stack:** PHP 8.3, Laravel 12, Eloquent, queues, Filament 3, Livewire 3, PHPUnit 11.

---

### Task 1: Reprocessar pedidos pendentes

**Files:**
- Create: `app/Services/MicrosoftGraph/RemovalRequests/RetryRemovalRequestImport.php`
- Modify: `app/Models/IntegrationInboxItem.php`
- Test: `tests/Feature/Services/RetryRemovalRequestImportTest.php`

- [ ] **Step 1: Escrever o teste de retry que falha**

Criar um item `removal_request` com status `pending` e `failure_reason = domain_error`. Chamar o servico e verificar que o item fica `queued`, o motivo e `resolved_at` sao limpos e `ProcessRemovalRequestEmail` e despachado.

```php
Queue::fake();

$item = IntegrationInboxItem::factory()->create([
    'message_type' => 'removal_request',
    'status' => 'pending',
    'failure_reason' => 'domain_error',
]);

app(RetryRemovalRequestImport::class)->handle($item);

$this->assertSame('queued', $item->refresh()->status);
$this->assertNull($item->failure_reason);
$this->assertNull($item->resolved_at);
Queue::assertPushed(ProcessRemovalRequestEmail::class, fn (ProcessRemovalRequestEmail $job): bool => $job->integrationInboxItemId === $item->id);
```

- [ ] **Step 2: Confirmar RED**

Run:

```bash
php artisan test --compact tests/Feature/Services/RetryRemovalRequestImportTest.php
```

Expected: FAIL porque o servico ainda nao existe.

- [ ] **Step 3: Implementar o servico minimo**

Criar `RetryRemovalRequestImport::handle(IntegrationInboxItem $item): IntegrationInboxItem`. Dentro de uma transacao com lock, aceitar somente pedidos de remocao nos estados `pending` ou `alert`, limpar `failure_reason` e `resolved_at`, definir `queued` e despachar `ProcessRemovalRequestEmail` com `afterCommit()`. Para estados finais ou tipos diferentes, lancar `DomainException`.

- [ ] **Step 4: Adicionar os casos de concorrencia e rejeicao**

Cobrir no mesmo teste: checklist nao pode ser reprocessado; item `processed` nao pode ser reprocessado; duas chamadas para o mesmo item devem manter um unico estado `queued` e depender da unicidade do job.

- [ ] **Step 5: Confirmar GREEN e formatar**

Run:

```bash
php artisan test --compact tests/Feature/Services/RetryRemovalRequestImportTest.php
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commitar a task**

```bash
git add app/Services/MicrosoftGraph/RemovalRequests/RetryRemovalRequestImport.php app/Models/IntegrationInboxItem.php tests/Feature/Services/RetryRemovalRequestImportTest.php
git commit -m "feat(integration): retry failed removal imports"
```

### Task 2: Adicionar aceite total e estados claros no Filament

**Files:**
- Modify: `app/Filament/Resources/IntegrationInboxItemResource.php`
- Modify: `app/Models/IntegrationInboxItem.php`
- Test: `tests/Feature/Filament/IntegrationInboxItemResourceTest.php`

- [ ] **Step 1: Escrever os testes de acoes e conciliacao sem opcoes**

Adicionar testes que verifiquem:

```php
$table = Livewire::test(ListIntegrationInboxItems::class)->instance()->getTable();

$removal = IntegrationInboxItem::factory()->create([
    'message_type' => 'removal_request',
    'status' => 'pending',
    'proposed_changes' => ['value' => ['current' => '800.00', 'proposed' => '866.48']],
]);
$domainError = IntegrationInboxItem::factory()->create([
    'message_type' => 'removal_request',
    'status' => 'pending',
    'failure_reason' => 'domain_error',
]);
$checklist = IntegrationInboxItem::factory()->create([
    'message_type' => 'checklist',
    'status' => 'pending',
]);

$this->assertTrue($table->getAction('acceptRemovalRequest')->record($removal)->isVisible());
$this->assertTrue($table->getAction('reviewRemovalRequest')->record($removal)->isVisible());
$this->assertTrue($table->getAction('retryRemovalRequest')->record($domainError)->isVisible());
$this->assertFalse($table->getAction('acceptRemovalRequest')->record($domainError)->isVisible());
$this->assertFalse($table->getAction('retryRemovalRequest')->record($checklist)->isVisible());
```

Criar tambem um item checklist sem `Register` compativel e verificar que a acao `resolve` fica desabilitada e informa que nao ha registro compativel.

- [ ] **Step 2: Confirmar RED**

Run:

```bash
php artisan test --compact tests/Feature/Filament/IntegrationInboxItemResourceTest.php
```

Expected: FAIL porque as novas acoes e o estado desabilitado ainda nao existem.

- [ ] **Step 3: Implementar `acceptRemovalRequest`**

Adicionar uma acao de confirmacao visivel para pedidos `pending` que possuam `proposed_changes` ou `candidate_pdf_path`. Ela deve chamar `ResolveRemovalRequestImport::apply()` com todas as chaves propostas e `replacePdf = true` quando houver PDF candidato. Se nao houver campos nem candidato, a acao nao aparece.

- [ ] **Step 4: Implementar `retryRemovalRequest`**

Adicionar uma acao de cor `warning`, visivel somente para pedido de remocao `pending` com falha de processamento (`domain_error`, `processing_failed` ou `graph_connection_missing`) e chamar `RetryRemovalRequestImport`. Exibir notificacao de sucesso apos o despacho.

- [ ] **Step 5: Desabilitar conciliacao sem correspondencia**

Extrair a montagem das opcoes compativeis para um metodo privado da resource. Usar o mesmo resultado no `Select` e em `Action::disabled()`. Quando vazio, manter a acao visivel para diagnostico, mas desabilitada, com tooltip `Nenhum registro compatível encontrado para esta baixa.`. O `Select` tambem deve ficar desabilitado e nao permitir envio.

- [ ] **Step 6: Traduzir os novos motivos**

Adicionar em `IntegrationInboxItem::failureReasonLabel()` os rotulos de `domain_error`, `processing_failed` e `graph_connection_missing`, evitando que o painel exponha somente chaves internas.

- [ ] **Step 7: Confirmar GREEN e formatar**

Run:

```bash
php artisan test --compact tests/Feature/Filament/IntegrationInboxItemResourceTest.php tests/Feature/Services/RetryRemovalRequestImportTest.php
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Commitar a task**

```bash
git add app/Filament/Resources/IntegrationInboxItemResource.php app/Models/IntegrationInboxItem.php tests/Feature/Filament/IntegrationInboxItemResourceTest.php
git commit -m "feat(filament): add removal import decisions"
```

### Task 3: Verificacao final

**Files:**
- Modify: `docs/superpowers/specs/2026-08-25-removal-import-review-actions-design.md`
- Modify: `docs/superpowers/plans/2026-08-25-removal-import-review-actions.md`

- [ ] **Step 1: Rodar a cobertura focada**

```bash
php artisan test --compact tests/Feature/Services/RetryRemovalRequestImportTest.php tests/Feature/Filament/IntegrationInboxItemResourceTest.php tests/Feature/MicrosoftGraph/ProcessRemovalRequestEmailTest.php
```

Expected: todos PASS.

- [ ] **Step 2: Rodar a suite completa**

```bash
php artisan test --compact
```

Expected: todos PASS, sem regressao no importer, conciliacao ou limpeza.

- [ ] **Step 3: Verificar qualidade e atualizar o plano**

```bash
vendor/bin/pint --dirty --format agent
git status --short --branch
```

Marcar as tasks concluidas no plano e confirmar que a branch nao possui alteracoes nao commitadas.

- [ ] **Step 4: Commit final**

```bash
git add docs/superpowers/specs/2026-08-25-removal-import-review-actions-design.md docs/superpowers/plans/2026-08-25-removal-import-review-actions.md
git commit -m "test(filament): verify removal import review flow"
```
