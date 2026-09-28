# CIOT Emission Seam Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir as cinco coreografias copiadas de emissao de CIOT por um unico seam (`EmitCiotDeclaration::emit()/enqueue()` + `CiotEmissionResult` + `CiotEmissionNotice`), mover a maquina de estados para o model (`Ciot::transitionTo()/transitionFrom()`) e matar os seis defeitos latentes D1-D6 sem alterar nenhuma string de notificacao, redirect ou guard coberto por teste.

**Architecture:** Strangler em quatro fases verdes. O service `EmitCiotDeclaration` evolui in place: `attempt()` e o contrato bruto (prepare + declare, lanco cru), `emit()/enqueue()` sao as entradas de UI que devolvem um outcome em vez de exigir try/catch no caller. As paginas viram callers de ~3 statements (criar/salvar/buscar + emit + notice + redirect). Transicoes com lock ficam no model; os servicos `DeclareCiot`/`CancelCiot`/`CloseCiot`/`EmitCiotJob` apenas orquestram.

**TechStack:** PHP 8.3, Laravel 12, Eloquent, Filament 3, Livewire 3, PHPUnit 11, MySQL.

---

## Referencia

- Design aprovado na revisao de 2026-09-28 (condensado na secao Design abaixo; nao existe spec em `docs/superpowers/specs/`).
- Documentacao consultada: Filament 3 notifications/actions; Laravel 12 transactions, `lockForUpdate()` e queue jobs (`ShouldBeUnique`, `WithoutOverlapping`).
- Regra de formatacao: executar `vendor/bin/pint --dirty --format agent` ao final de cada fase que altere PHP.

## Contexto

Cinco coreografias copiadas chamam `app(EmitCiotDeclaration::class)->handle($x, sync: true)` e repetem o mesmo baile catch -> `report()` -> `EmitCiotJob::dispatch()` -> notificacao -> redirect:

1. `CreateCiot::createAndEmit` — `app/Filament/Resources/CiotResource/Pages/CreateCiot.php:45-89`. Warning `CIOT criado — emissão em processamento`, success `CIOT emitido: {fullNumber}`, danger `Emissão rejeitada pela ANTT`, redirect para a view do CIOT.
2. `EditCiot::saveAndEmit` — `app/Filament/Resources/CiotResource/Pages/EditCiot.php:60-100`. `$this->save()` antes; warning `CIOT salvo — emissão em processamento`. **D1**: importa `Filament\Support\Notifications\Notification` (linha 12), classe inexistente — toda notificacao fatal depois dos side-effects persistidos.
3. Acao `emit` da `ViewCiot` — `app/Filament/Resources/CiotResource/Pages/ViewCiot.php:28-74`. Warning `Emissão em processamento`. **D1** identico (linha 11).
4. `GenerateCiotForBatchAction::submit` — `app/Filament/Actions/GenerateCiotForBatchAction.php:284-346`. Guard de duplicado `Este lote já possui um CIOT ativo` primeiro (286-299); cria o `Ciot` com `public_id` + `cte_emission_batch_id`; redirect via helper global `redirect()->to()`. **D5**: guard e um `exists()` solto fora de transacao (corrida cria CIOT duplicado no lote).
5. `ViewCteEmissionBatch::reemitCiot` — `app/Filament/Resources/CteEmissionBatchResource/Pages/ViewCteEmissionBatch.php:62-120`. Busca o ultimo CIOT FAILED (null-guard `Nenhum CIOT com falha neste lote.`); **D2**: sem `use Throwable;` nem `use App\Jobs\EmitCiotJob;` — o `catch (Throwable)` (88) nunca casa (resolve para `App\...\Pages\Throwable`) e o `EmitCiotJob::dispatch` (91) fatal. Copy de reemissao: `Reemissão em processamento` / `CIOT reemitido: {fullNumber}` / `Reemissão rejeitada pela ANTT`; redirect para a view do LOTE.

Sexto site (async, formato diferente): acao `emit` da tabela `ListCiots` — `app/Filament/Resources/CiotResource.php:404-436` — `handle($record)` sem sync; `AnttCiotException|DomainException` -> `Falha ao emitir o CIOT`; `Throwable` -> `Erro inesperado ao emitir o CIOT`; success `Emissão enfileirada.`; modal `O CIOT será enviado para a ANTT em segundo plano com retry automático.`

Ha dois caminhos de criacao de CIOT que convergem na emissao verificada pelo usuario: manual (form da `CiotResource`, passos 1-3) e via lote de CT-e (`GenerateCiotForBatchAction` faz prefill de `PrefillCiotFromBatch`, passo 4).

Defeitos latentes verificados (D1, D2 e D5 acima, mais):

- **D3** (todas as copias): excecoes de `BuildCiotDeclarationPayload` (`DomainException`, roda em `app/Services/Ciot/EmitCiotDeclaration.php:22`) ou `generateIdOperacaoTransporte` (`AnttCiotException`, `:24`) estouram ANTES de `status = PENDING`; o fallback despacha `EmitCiotJob` -> guard do `DeclareCiot` (`status !== PENDING`, `app/Services/Ciot/DeclareCiot.php:20-22`) faz no-op -> o usuario le "retry automatico" mas nada retries.
- **D4**: `DomainException` de guard capturado pelo `catch (Throwable)` das paginas -> job no-op -> warning falso de "em processamento".
- **D6**: `CreateCiot::mutateFormDataBeforeCreate` (`CreateCiot.php:95-111`) duplica `CiotResource::transformFormData` (`CiotResource.php:536-552`) SEM a poda B119 `withoutPayerCnpjs` — create avulso pode cadastrar pagante repetido em contratantes adicionais.

Camada de servico hoje: `EmitCiotDeclaration::handle(Ciot, bool $sync=false)` (`app/Services/Ciot/EmitCiotDeclaration.php:20-57`) faz prepare -> transicao PENDING sob lock -> sync ? `DeclareCiot` : dispatch. `DeclareCiot::handle` (`app/Services/Ciot/DeclareCiot.php:16-74`) guarda PENDING+payload, declara via HTTP, `markFailed` condicional (`:76-88`) e, apos ISSUED, dispara MDF-e do lote (`:69-71`, idempotente). `CancelCiot`/`CloseCiot` fazem HTTP antes da transacao e merge de response sob `cancelamento`/`encerramento`. `EmitCiotJob` (`app/Jobs/EmitCiotJob.php`): `ShouldBeUnique` + `WithoutOverlapping('ciot-emission:'.$id, 30, 600)`, `tries=3`, `timeout=120`, `backoff [60,300]`.

Cobertura atual: `tests/Feature/Services/Ciot/EmitCiotDeclarationTest.php` (enqueue+PENDING 36-56, guard ISSUED 58-65, job via `(new EmitCiotJob($id))->handle(app(DeclareCiot::class))` em 86/114/140/163/191, failed-hook 202-216); `tests/Feature/Filament/CiotResourceTest.php` (createAndEmit happy 236-286, enqueue assincrono 102-120); `tests/Feature/Filament/CteEmissionBatchResourceTest.php` (generateCiot 154-375, duplicado 425-462, reemitCiot 377-423). NUNCA cobertos: `EditCiot::saveAndEmit`, `ViewCiot` emit, ramos de rejeicao/fallback via UI, ramo null do reemitCiot, ramos catch da `ListCiots`. `tests/Feature/Services/Ciot/CiotCancelAndCloseTest.php` nao pode ser tocado.

## Design

### Novas assinaturas

```php
// app/Services/Ciot/EmitCiotDeclaration.php
public function emit(Ciot $ciot): CiotEmissionResult;    // entrada sincrona (botoes "criar/salvar/emitir")
public function enqueue(Ciot $ciot): CiotEmissionResult;  // entrada assincrona (tabela)
public function attempt(Ciot $ciot): Ciot;                // contrato interno + EmitCiotJob; lanca cru
```

- `emit()`: tenta `attempt()`; `DomainException` -> Invalid (sem dispatch, sem `report()`); `AnttCiotException` non-retryable do prepare -> Invalid; retryable ou `Throwable` -> `report()` + `EmitCiotJob::dispatch` + Queued.
- `enqueue()`: prepare sincrono; `DomainException`/`AnttCiotException` (retryable ou nao) -> Invalid; `Throwable` -> `report()` + Errored; sucesso -> Enqueued.
- `attempt()`: prepare-if-needed — re-prepara quando `status in [DRAFT, FAILED]` **ou** `payload` vazio (FAILED sempre regera o IdOperacaoTransporte, prometido no modal do reemitCiot); PENDING com payload pula direto para `DeclareCiot::handle`. Nao captura nada.

```php
// app/Models/Ciot.php
public function transitionTo(CiotStatusEnum $target, array $fields = [], ?string $guardMessage = null): self;
public function transitionFrom(CiotStatusEnum $expected, CiotStatusEnum $target, callable $fields): ?self;

// app/Enums/CiotStatusEnum.php
/** @return list<CiotStatusEnum> */
public function allowedSources(): array; // PENDING<-[DRAFT,FAILED]; ISSUED<-[PENDING]; CANCELED<-[ISSUED]; CLOSED<-[ISSUED]; FAILED<-[PENDING,DRAFT]; DRAFT<-[]
```

Ambos: `DB::transaction()` + re-fetch `lockForUpdate()` + `forceFill($fields + status)->save()` (activity log preservado). `transitionTo` lanca `DomainException` em violacao (`$guardMessage` mantem o wording legacy). `transitionFrom` e otimista: retorna `null` quando o status travado difere do esperado; o callable recebe a instancia TRAVADA (merge de response le dados frescos).

`CiotEmissionResult` (readonly): `outcome`, `Ciot` fresco, `?string $message`; helpers `isIssued()` e `fullNumber()`. `CiotEmissionNotice` (readonly config): defaults = strings verbatim das copias; `static reemission()`; `send(CiotEmissionResult)` mapeia outcome -> notificacao.

### Tabela de outcomes

| Outcome | Origem | Status do registro | Notificacao |
|---|---|---|---|
| Issued | `attempt()` terminou ISSUED | ISSUED | success `CIOT emitido: {fullNumber}` |
| Failed | rejeicao de negocio ANTT (`markFailed`) | FAILED | danger `Emissão rejeitada pela ANTT` + body `message` |
| Invalid | `DomainException` (guard/regra) ou `AnttCiotException` non-retryable no prepare | inalterado (DRAFT/FAILED) | danger `Falha ao emitir o CIOT` + body `message` |
| Queued (so `emit()`) | retryable/`Throwable` apos side-effects persistidos | PENDING | warning `queuedTitle` + `queuedBody` |
| Enqueued (so `enqueue()`) | prepare ok + job despachado | PENDING | success `Emissão enfileirada.` |
| Errored (so `enqueue()`) | `Throwable` no prepare (`report()`) | inalterado | danger `Erro inesperado ao emitir o CIOT` |

### Strings do `CiotEmissionNotice` (verbatim)

- `issuedTitle`: `'CIOT emitido: '` + `fullNumber()`; `enqueuedTitle`: `'Emissão enfileirada.'`.
- `queuedTitle` (default `'CIOT criado — emissão em processamento'`; `EditCiot` sobrescreve `'CIOT salvo — emissão em processamento'`; `ViewCiot` sobrescreve `'Emissão em processamento'`).
- `queuedBody`: `'A ANTT não respondeu agora; a emissão ficou na fila com retry automático.'`
- `rejectedTitle` (Failed): `'Emissão rejeitada pela ANTT'`; `invalidTitle` (Invalid): `'Falha ao emitir o CIOT'`; `erroredTitle`: `'Erro inesperado ao emitir o CIOT'` + body `'Tente novamente; se persistir, contate o suporte.'`.
- `reemission()`: `Reemissão em processamento` / `'A ANTT não respondeu agora; a reemissão ficou na fila com retry automático.'` / `'CIOT reemitido: '` / `'Reemissão rejeitada pela ANTT'`.

### Byte-identical vs mudancas intencionais

Fica byte-identical: todas as strings de notificacao acima, redirects (view do CIOT / view do lote), guards (`Somente CIOTs em rascunho ou com falha podem ser emitidos.`, `Somente CIOTs emitidos podem ser cancelados/encerrados.`), activity logs via `save()`, hook MDF-e apos ISSUED, `ShouldBeUnique`/`WithoutOverlapping(30,600)`/`tries=3`/`timeout=120`/`backoff [60,300]`, merge de response sob `cancelamento`/`encerramento`, HTTP-antes-do-lock em cancel/close.

Muda intencionalmente: correcoes D1-D6; `DomainException` deixa de ser `report()`ado no caminho sync (e resultado esperado, nao erro); `DomainException` dentro do job e terminal (marca FAILED sem queimar 3 tentativas + backoff); `failed()` do job pode marcar DRAFT->FAILED; `markFailed` passa por `save()` (a transicao FAILED agora gera activity log — antes era UPDATE cru sem evento).

## Estrutura de Arquivos

### Criar

- `app/Enums/CiotEmissionOutcome.php`: cases `Issued`/`Failed`/`Invalid`/`Queued`/`Enqueued`/`Errored`.
- `app/Services/Ciot/CiotEmissionResult.php`: resultado readonly com helpers.
- `app/Filament/Support/CiotEmissionNotice.php`: config de strings verbatim + `reemission()` + `send()`.
- `app/Services/Ciot/CreateCiotForBatch.php`: criacao transacional com guard de duplicado sob lock no lote (D5).
- `tests/Feature/Models/CiotTransitionTest.php`: mapa, throw e null otimista.
- `tests/Feature/Services/Ciot/EmitCiotOutcomeTest.php`: matriz de outcomes via `emit()`/`enqueue()` + regressao D3.
- `tests/Feature/Services/Ciot/CreateCiotForBatchTest.php`: contrato do servico de criacao por lote.

### Modificar

- `app/Enums/CiotStatusEnum.php`: `allowedSources()`.
- `app/Models/Ciot.php`: `transitionTo()`/`transitionFrom()`.
- `app/Services/Ciot/EmitCiotDeclaration.php`: internos sobre transicoes (Fase 1); `attempt`/`emit`/`enqueue` (Fase 2); remover `handle()`/`bool $sync` (Fase 4).
- `app/Services/Ciot/DeclareCiot.php`: caminho de sucesso e `markFailed` sobre `transitionFrom`.
- `app/Services/Ciot/CancelCiot.php`: ritual de transicao substituido (guard e HTTP intocados).
- `app/Services/Ciot/CloseCiot.php`: idem.
- `app/Jobs/EmitCiotJob.php`: `handle()` tipa `EmitCiotDeclaration` + `attempt()` (D3); `DomainException` terminal; `failed()` encadeado.
- `app/Filament/Resources/CiotResource/Pages/CreateCiot.php`: `emit()` + notice; D6 (`transformFormData` + `public_id`).
- `app/Filament/Resources/CiotResource/Pages/EditCiot.php`: `emit()` + notice; D1 morre.
- `app/Filament/Resources/CiotResource/Pages/ViewCiot.php`: `emit()` + notice; D1 morre.
- `app/Filament/Actions/GenerateCiotForBatchAction.php`: `CreateCiotForBatch` + `emit()` + notice; D5 morre.
- `app/Filament/Resources/CteEmissionBatchResource/Pages/ViewCteEmissionBatch.php`: reemitCiot com `emit()` + `reemission()`; D2 morre.
- `app/Filament/Resources/CiotResource.php`: acao `emit` da `ListCiots` usa `enqueue()` + notice; D4 morre.
- `tests/Feature/Services/Ciot/EmitCiotDeclarationTest.php`: atualizacoes mecanicas (Fases 2 e 4).
- `tests/Feature/Filament/CiotResourceTest.php`: testes parametrizados por outcome em CreateCiot/EditCiot/ViewCiot.
- `tests/Feature/Filament/CteEmissionBatchResourceTest.php`: ramo null do reemitCiot.

Nao modificar: `tests/Feature/Services/Ciot/CiotCancelAndCloseTest.php` (prova que cancel/close ficam byte-identical).

## Fases

### Fase 1 — Seam de estados no model

**Files:**
- Modify: `app/Enums/CiotStatusEnum.php`
- Modify: `app/Models/Ciot.php`
- Modify: `app/Services/Ciot/EmitCiotDeclaration.php`
- Modify: `app/Services/Ciot/DeclareCiot.php`
- Modify: `app/Services/Ciot/CancelCiot.php`
- Modify: `app/Services/Ciot/CloseCiot.php`
- Modify: `app/Jobs/EmitCiotJob.php`
- Create: `tests/Feature/Models/CiotTransitionTest.php`

- [x] **1.1 Escrever o teste RED de transicoes**

Criar `tests/Feature/Models/CiotTransitionTest.php` cobrindo: o mapa completo de `allowedSources()` (`PENDING<-[DRAFT,FAILED]`, `ISSUED<-[PENDING]`, `CANCELED<-[ISSUED]`, `CLOSED<-[ISSUED]`, `FAILED<-[PENDING,DRAFT]`, `DRAFT<-[]`); `transitionTo(PENDING, [...])` a partir de DRAFT grava os campos e o status; violacao lanca `DomainException` com o `$guardMessage` informado (provar o wording legacy `'Somente CIOTs em rascunho ou com falha podem ser emitidos.'`); `transitionFrom(PENDING, ISSUED, ...)` retorna `null` quando o status travado diverge; o callable recebe a instancia travada (merge de `response` le o dado fresco do banco, nao o da instancia chamadora); `save()` segue disparando o activity log (`logOnlyDirty` em `app/Models/Ciot.php:85-90`).

Run: `php artisan test --compact tests/Feature/Models/CiotTransitionTest.php`
Expected: FAIL (metodos ausentes).

- [x] **1.2 Implementar `allowedSources()` e as transicoes**

Em `CiotStatusEnum` (apos `isOpen()`, `app/Enums/CiotStatusEnum.php:37-40`), adicionar `allowedSources(): array` com o mapa acima. Em `Ciot` (junto de `isOpen()`, `app/Models/Ciot.php:68-71`), adicionar `transitionTo()` e `transitionFrom()` com as assinaturas da secao Design: `DB::transaction()` + re-fetch `lockForUpdate()` + `forceFill($fields + ['status' => $target])->save()`. `transitionFrom` monta o payload via `callable(Ciot $locked): array` e retorna `null` sem mutar quando o status travado difere de `$expected`.

Run: `php artisan test --compact tests/Feature/Models/CiotTransitionTest.php`
Expected: PASS.

- [x] **1.3 Refactorar `EmitCiotDeclaration::handle`**

Substituir o bloco `DB::transaction` de `app/Services/Ciot/EmitCiotDeclaration.php:28-48` por uma chamada a `transitionTo(PENDING, ['id_operacao_transporte' => ..., 'payload' => ..., 'error_code' => null, 'error_message' => null, 'response' => null], guardMessage: 'Somente CIOTs em rascunho ou com falha podem ser emitidos.')`. Prepare (`:22-26`) e o ramo sync/dispatch (`:50-56`) permanecem identicos.

Run: `php artisan test --compact tests/Feature/Services/Ciot/EmitCiotDeclarationTest.php`
Expected: PASS sem edicao do teste.

- [x] **1.4 Refactorar `DeclareCiot` (sucesso e `markFailed`)**

Caminho de sucesso (`app/Services/Ciot/DeclareCiot.php:42-63`): o bloco transacao+lock+re-check+forceFill vira `transitionFrom(PENDING, ISSUED, fn (Ciot $locked): array => [... campos ANTT ...])`, retornando a instancia travada quando o otimista devolver `null` (re-check falho). Guard de entrada (`:20-22`) e hook MDF-e (`:69-71`) intocados. `markFailed` (`:76-88`): `transitionFrom(PENDING, FAILED, ...)` com os campos `error_code`/`error_message`; quando `null` (registro nao estava mais PENDING), devolver `$ciot->refresh()`. Nota: a transicao FAILED agora passa por `save()` e gera activity log — mudanca benigna registrada na secao de risco.

Run: `php artisan test --compact tests/Feature/Services/Ciot/EmitCiotDeclarationTest.php`
Run: `php artisan test --compact tests/Feature/Services/Ciot/MdfeDispatchTest.php`
Expected: PASS.

- [x] **1.5 Refactorar `CancelCiot` e `CloseCiot`**

Em `app/Services/Ciot/CancelCiot.php:38-56` e `app/Services/Ciot/CloseCiot.php:43-60`, substituir apenas o ritual (transacao+lock+re-check+forceFill) por `transitionFrom(ISSUED, CANCELED/CLOSED, fn (Ciot $locked): array => [...])`; o merge `'response' => array_merge($locked->response ?? [], ['cancelamento'/'encerramento' => $response->body])` le a instancia travada. Guards (`:16-24`/`:17-29`) e a chamada HTTP antes da transacao permanecem exatamente onde estao.

Run: `php artisan test --compact tests/Feature/Services/Ciot/CiotCancelAndCloseTest.php`
Expected: PASS sem edicao do teste (arquivo intocavel).

- [x] **1.6 Refactorar `EmitCiotJob::failed`**

`app/Jobs/EmitCiotJob.php:56-67`: `forceFill` condicional vira `transitionFrom(PENDING, FAILED, fn () => ['error_code' => 'job_exhausted', 'error_message' => $exception->getMessage()])`; encontrar o registro segue igual.

Run: `php artisan test --compact --filter=test_failed_hook_marks_pending_ciot_as_failed`
Expected: PASS.

- [x] **1.7 Fechar a fase**

Run: `php artisan test --compact tests/Feature/Models/CiotTransitionTest.php tests/Feature/Services/Ciot/EmitCiotDeclarationTest.php tests/Feature/Services/Ciot/CiotCancelAndCloseTest.php tests/Feature/Services/Ciot/MdfeDispatchTest.php tests/Feature/Filament/CiotResourceTest.php tests/Feature/Filament/CteEmissionBatchResourceTest.php`
Run: `vendor/bin/pint --dirty --format agent`

Commit autocontido e revertivel: `git commit -m "refactor(ciot): model state transition seam"`.

### Fase 2 — Seam de resultado (outcome)

**Files:**
- Create: `app/Enums/CiotEmissionOutcome.php`
- Create: `app/Services/Ciot/CiotEmissionResult.php`
- Create: `app/Filament/Support/CiotEmissionNotice.php`
- Modify: `app/Services/Ciot/EmitCiotDeclaration.php`
- Modify: `app/Jobs/EmitCiotJob.php`
- Modify: `tests/Feature/Services/Ciot/EmitCiotDeclarationTest.php`
- Create: `tests/Feature/Services/Ciot/EmitCiotOutcomeTest.php`

- [ ] **2.1 Gerar os artefatos**

Run:
```bash
php artisan make:enum Enums/CiotEmissionOutcome --no-interaction
php artisan make:class Services/Ciot/CiotEmissionResult --no-interaction
php artisan make:class Filament/Support/CiotEmissionNotice --no-interaction
```

`CiotEmissionOutcome`: cases `Issued`, `Failed`, `Invalid`, `Queued`, `Enqueued`, `Errored`. `CiotEmissionResult`: `__construct(public readonly CiotEmissionOutcome $outcome, public readonly Ciot $ciot, public readonly ?string $message = null)` + `isIssued(): bool` + `fullNumber(): ?string`. `CiotEmissionNotice`: props readonly da secao Design, `static reemission(): self`, `send(CiotEmissionResult $result): void` usando `Filament\Notifications\Notification` (a classe correta — `Filament\Support\Notifications\Notification` nao existe).

- [ ] **2.2 Escrever a matriz RED de outcomes**

Criar `tests/Feature/Services/Ciot/EmitCiotOutcomeTest.php` (mesmo `setUp()` de config do `EmitCiotDeclarationTest.php:21-34`) cobrindo via `emit()`: Issued (HTTP aceita), Failed (rejeicao de negocio, `error_message` no `message`), Invalid por `DomainException` (CIOT `issued()` — hoje o guard dispara em `BuildCiotDeclarationPayload.php:47-50`; registro inalterado, `Queue::assertNothingPushed`, sem `report()`), Invalid por `AnttCiotException` non-retryable no prepare (`/gerar` responde 400 com codigo), Queued por retryable no declare (`/api/DeclaracaoOperacaoTransporte` 500 -> `report()` + pushed + PENDING). Via `enqueue()`: Enqueued (prepare ok + pushed), Invalid por `DomainException` e por retryable do prepare (`/gerar` 500 — registro inalterado, sem push), Errored por `Throwable` (conexao recusada -> `report()`, registro inalterado). Regressao D3: `/gerar` 500 -> `emit()` devolve Queued com registro DRAFT -> arrumar `/gerar` 200 + declare aceito -> `(new EmitCiotJob($id))->handle(app(EmitCiotDeclaration::class))` -> ISSUED. E o caso terminal do job: `DomainException` dentro do handle marca FAILED sem relancar.

Run: `php artisan test --compact tests/Feature/Services/Ciot/EmitCiotOutcomeTest.php`
Expected: FAIL (metodos ausentes).

- [ ] **2.3 Implementar `attempt`/`emit`/`enqueue`; `handle` vira adapter**

Em `EmitCiotDeclaration`: `attempt()` (prepare-if-needed `[DRAFT, FAILED] || payload vazio` -> `transitionTo(PENDING)` -> `DeclareCiot::handle`, lancando cru), `emit()` e `enqueue()` com a semantica da secao Design. `handle(Ciot, bool $sync)` permanece como adapter fino preservando o contrato atual: `sync: true` -> `return $this->attempt($ciot)` (lanca como hoje); `sync: false` -> o caminho interno compartilhado por `enqueue()` (prepare + dispatch + registro fresco). Nenhum call site muda nesta fase.

Run: `php artisan test --compact tests/Feature/Services/Ciot/EmitCiotOutcomeTest.php`
Run: `php artisan test --compact tests/Feature/Services/Ciot/EmitCiotDeclarationTest.php`
Expected: PASS.

- [ ] **2.4 Rewirar o job (D3) e atualizar testes mecanicamente**

`EmitCiotJob::handle` (`app/Jobs/EmitCiotJob.php:45-54`): type-hint `EmitCiotDeclaration` e chamar `attempt($ciot)`; envolver em `try/catch (DomainException)` -> `transitionTo(FAILED, ['error_code' => null, 'error_message' => $e->getMessage()])` + `return` (terminal, sem queimar tentativas). Cuidado: se o registro JÁ esta FAILED (job re-tentando um CIOT com regras ainda violadas), `FAILED->FAILED` nao esta no mapa e `transitionTo` relancaria dentro do catch — pular a transicao quando o status ja for FAILED (apenas atualizar `error_message` ou retornar). `failed()`: encadear `transitionFrom(PENDING, FAILED, ...) ?? transitionFrom(DRAFT, FAILED, ...)`. Em `tests/Feature/Services/Ciot/EmitCiotDeclarationTest.php`: trocar `app(DeclareCiot::class)` por `app(EmitCiotDeclaration::class)` nas linhas 86, 114, 140, 163 e 191; `test_cannot_enqueue_an_issued_ciot` (58-65) passa a chamar `enqueue()` e assertar `CiotEmissionOutcome::Invalid` + `Queue::assertNothingPushed`; `test_failed_hook_marks_pending_ciot_as_failed` (202-216) ganha um caso DRAFT->FAILED. Middleware/tries/backoff intocados.

Run: `php artisan test --compact tests/Feature/Services/Ciot/EmitCiotDeclarationTest.php`
Run: `php artisan test --compact tests/Feature/Services/Ciot/EmitCiotOutcomeTest.php`
Run: `php artisan test --compact tests/Feature/Filament/CiotResourceTest.php tests/Feature/Filament/CteEmissionBatchResourceTest.php`
Expected: PASS (UI ainda usa `handle()`, adapter preserva contrato).

- [ ] **2.5 Fechar a fase**

Run: `php artisan test --compact tests/Feature/Services/Ciot/ tests/Feature/Models/CiotTransitionTest.php tests/Feature/Filament/CiotResourceTest.php tests/Feature/Filament/CteEmissionBatchResourceTest.php`
Run: `vendor/bin/pint --dirty --format agent`

Commit: `git commit -m "feat(ciot): emission outcome seam"`.

### Fase 3 — Migrar os seis call sites (D1/D2/D4/D5/D6 morrem)

**Files:**
- Create: `app/Services/Ciot/CreateCiotForBatch.php`
- Create: `tests/Feature/Services/Ciot/CreateCiotForBatchTest.php`
- Modify: `app/Filament/Resources/CiotResource/Pages/CreateCiot.php`
- Modify: `app/Filament/Resources/CiotResource/Pages/EditCiot.php`
- Modify: `app/Filament/Resources/CiotResource/Pages/ViewCiot.php`
- Modify: `app/Filament/Actions/GenerateCiotForBatchAction.php`
- Modify: `app/Filament/Resources/CteEmissionBatchResource/Pages/ViewCteEmissionBatch.php`
- Modify: `app/Filament/Resources/CiotResource.php`
- Modify: `tests/Feature/Filament/CiotResourceTest.php`
- Modify: `tests/Feature/Filament/CteEmissionBatchResourceTest.php`

- [ ] **3.1 Criar `CreateCiotForBatch` (D5)**

RED em `tests/Feature/Services/Ciot/CreateCiotForBatchTest.php`: criacao feliz devolve `Ciot` com `public_id` (UUID), `cte_emission_batch_id`, campos de `transformFormData` e activity log `"CIOT gerado pelo lote de CT-e #{$batch->id}."`; lote com CIOT ativo lanca `DomainException('Este lote já possui um CIOT ativo')`; lote so com CIOT `canceled` passa. Implementar com `DB::transaction()` + `lockForUpdate()` na LINHA do lote (`CteEmissionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail()`) serializando a corrida, guard `whereNotIn('status', [CANCELED])` DENTRO da transacao, e o bloco absorvido de `GenerateCiotForBatchAction::submit` (`app/Filament/Actions/GenerateCiotForBatchAction.php:301-310`).

Run: `php artisan test --compact tests/Feature/Services/Ciot/CreateCiotForBatchTest.php` — Expected: FAIL, depois GREEN.

- [ ] **3.2 Migrar `CreateCiot` (D6 junto)**

`createAndEmit` (`app/Filament/Resources/CiotResource/Pages/CreateCiot.php:45-89`) vira: criar registro (igual), `$result = app(EmitCiotDeclaration::class)->emit($record)`, `(new CiotEmissionNotice())->send($result)`, `$this->redirect(CiotResource::getUrl('view', ['record' => $result->ciot]))`. D6: `mutateFormDataBeforeCreate` (`:95-111`) passa a fazer `$data['public_id'] = (string) Str::uuid(); return CiotResource::transformFormData($data);` — a poda B119 `withoutPayerCnpjs` (`CiotResource.php:548`) passa a valer no create avulso.

Run: `php artisan test --compact tests/Feature/Filament/CiotResourceTest.php`
Expected: PASS — `test_create_and_emit_issues_the_ciot_synchronously` (236-286) asserta `CIOT emitido: 5200329599990001` e `Queue::assertNothingPushed` byte-identical; `test_the_create_form_builds_a_draft_ciot_snapshot` (59-100) prova o D6 sem quebrar.

- [ ] **3.3 Migrar `EditCiot` e `ViewCiot` (D1 morre) — primeira cobertura deles**

Remover o import fantasma `Filament\Support\Notifications\Notification` das linhas 12 (`EditCiot.php`) e 11 (`ViewCiot.php`). `saveAndEmit` (`EditCiot.php:60-100`): `$this->save()` + `emit()` + `(new CiotEmissionNotice(queuedTitle: 'CIOT salvo — emissão em processamento'))->send($result)` + redirect via `getRedirectUrl()`. Acao `emit` da `ViewCiot` (`ViewCiot.php:28-74`): `emit()` + notice com `queuedTitle: 'Emissão em processamento'` + redirect para a view do CIOT. Novos testes parametrizados em `tests/Feature/Filament/CiotResourceTest.php`: para cada site, happy path (assert `assertNotified('CIOT emitido: ...')`) + um caso por outcome de notice (Queued warning, Failed danger com body, Invalid danger com body).

Run: `php artisan test --compact tests/Feature/Filament/CiotResourceTest.php` — Expected: PASS.

- [ ] **3.4 Migrar `GenerateCiotForBatchAction`**

`submit` (`app/Filament/Actions/GenerateCiotForBatchAction.php:284-346`) vira: `try { $ciot = app(CreateCiotForBatch::class)->handle($batch, $data); } catch (DomainException) { warning verbatim 'Este lote já possui um CIOT ativo' + body 'Cancele o CIOT existente para emitir outro para esta viagem.'; return; }` + `emit()` + `(new CiotEmissionNotice())->send($result)` + `redirect()->to(...)` (helper global, como hoje). Remover imports mortos (`EmitCiotJob`, `Throwable`, `Str`, `Ciot` conforme sobrar).

Run: `php artisan test --compact tests/Feature/Filament/CteEmissionBatchResourceTest.php --filter=generate_ciot`
Expected: PASS — happy (154-227), additional payers (229-316), B119 (318-375) e duplicado (425-462) byte-identical.

- [ ] **3.5 Migrar `reemitCiot` (D2 morre) + ramo null**

`app/Filament/Resources/CteEmissionBatchResource/Pages/ViewCteEmissionBatch.php:62-120`: manter localmente a busca do ultimo CIOT FAILED + null-guard `Nenhum CIOT com falha neste lote.`; o resto vira `emit()` + `CiotEmissionNotice::reemission()->send($result)` + redirect para a view do LOTE. Adicionar imports (`CiotEmissionNotice`) e remover os mortos. Novo teste do ramo null (lote sem CIOT FAILED chama a acao e asserta o warning) em `tests/Feature/Filament/CteEmissionBatchResourceTest.php`.

Run: `php artisan test --compact tests/Feature/Filament/CteEmissionBatchResourceTest.php` — Expected: PASS (`CIOT reemitido: 5200329522220002` verbatim, 377-423).

- [ ] **3.6 Migrar a acao `emit` da `ListCiots` (D4 morre)**

`app/Filament/Resources/CiotResource.php:404-436`: os dois `catch` e o success manual viram `$result = app(EmitCiotDeclaration::class)->enqueue($record); (new CiotEmissionNotice())->send($result);` — sem redirect. Enqueued -> `Emissão enfileirada.`, Invalid -> `Falha ao emitir o CIOT` + mensagem real (D4: antes warning falso), Errored -> `Erro inesperado ao emitir o CIOT` verbatim.

Run: `php artisan test --compact tests/Feature/Filament/CiotResourceTest.php --filter=test_the_emit_action_enqueues_the_emission`
Expected: PASS (102-120 byte-identical).

- [ ] **3.7 Fechar a fase**

Run: `php artisan test --compact tests/Feature/Services/Ciot/CreateCiotForBatchTest.php tests/Feature/Services/Ciot/EmitCiotOutcomeTest.php tests/Feature/Services/Ciot/EmitCiotDeclarationTest.php tests/Feature/Models/CiotTransitionTest.php tests/Feature/Filament/CiotResourceTest.php tests/Feature/Filament/CteEmissionBatchResourceTest.php`
Run: `vendor/bin/pint --dirty --format agent`

Commit: `git commit -m "refactor(ciot): six call sites on the emission seam"`.

### Fase 4 — Remover o adapter legacy

**Files:**
- Modify: `app/Services/Ciot/EmitCiotDeclaration.php`
- Modify: `tests/Feature/Services/Ciot/EmitCiotDeclarationTest.php`

- [ ] **4.1 Deletar `handle()`/`bool $sync` e migrar o ultimo teste**

Remover `handle()` de `app/Services/Ciot/EmitCiotDeclaration.php`. `test_enqueueing_sets_the_ciot_pending_and_dispatches_the_job` (`tests/Feature/Services/Ciot/EmitCiotDeclarationTest.php:36-56`) passa a usar `enqueue()` e assertar `CiotEmissionOutcome::Enqueued`, `status` PENDING, `id_operacao_transporte` no payload e `Queue::assertPushed` com `ciotId`.

Run: `php artisan test --compact tests/Feature/Services/Ciot/EmitCiotDeclarationTest.php` — Expected: PASS.

- [ ] **4.2 Cacar sobreviventes**

Run:
```bash
grep -rn "EmitCiotDeclaration::class)->handle\|sync: true\|sync: false" app/ tests/
grep -rn "DeclareCiot" app/Filament app/Jobs tests/Feature/Filament
```
Expected: zero ocorrencias do primeiro grupo; `DeclareCiot` restante apenas em `app/Services/Ciot/` e nos tests de servico.

- [ ] **4.3 Suite completa e fechamento**

Run: `php artisan test --compact`
Run: `vendor/bin/pint --dirty --format agent`
Expected: todos PASS.

Commit: `git commit -m "refactor(ciot): drop legacy emission handle"`.

## O Que Pode Quebrar (Analise de Risco)

| Fase | Preservado byte-identical | Risco principal | Mitigacao | Rollback |
|---|---|---|---|---|
| 1 | Guards e mensagens (`Somente CIOTs em rascunho...`), HTTP-antes-do-lock em cancel/close, merge `cancelamento`/`encerramento` lendo dados travados, hook MDF-e, `CiotCancelAndCloseTest` intocado | Transicao otimista devolver `null` onde o codigo antigo retornava a instancia | Teste do callable-travado (1.1) + suites de servico intocadas apos cada refactor | `git revert` do commit da fase; sem schema |
| 2 | `handle()` adapter preserva contrato (sync lanca, async despacha); middleware `ShouldBeUnique`/`WithoutOverlapping(30,600)`, `tries=3`, `timeout=120`, `backoff [60,300]`; `failed()` marca `job_exhausted` | Job re-preparar registros DRAFT (D3) gerar `IdOperacaoTransporte` novo | Regressao D3 dedicada em `EmitCiotOutcomeTest`; condition `[DRAFT, FAILED] \|\| payload vazio` nao toca PENDING | Reverter; `handle()` ainda existira ate a Fase 4 |
| 3 | Strings verbatim, redirects (view do CIOT / view do lote), guard de duplicado + warning, activity logs, `Queue::assertNothingPushed` no happy sync | Perda de string copiada na migration dos seis sites | Testes parametrizados por outcome por site + happy paths existentes assertando as strings literais | Reverter; service e notice seguem funcionando |
| 4 | — | Call site esquecido usando `handle()` | grep 4.2 obrigatorio antes do commit | Reverter |

Mudancas intencionais (before -> after, visivel para o usuario):

- **D1**: EditCiot/ViewCiot fatalavam em toda notificacao (classe fantasma) -> notices renderizam.
- **D2**: fallback do reemitCiot nunca capturava e o dispatch fatalava -> reemissao enfileira de fato.
- **D3**: "retry automatico" mentia (job no-op em registro DRAFT) -> job prepara e emite.
- **D4**: guard virava warning falso "em processamento" -> danger com a mensagem real.
- **D5**: corrida podia criar CIOT duplicado no lote -> lock no lote serializa; perdedor recebe o warning de duplicado.
- **D6**: create avulso aceitava pagante repetido em adicionais (rejeicao B119 tardia na ANTT) -> podado no formulario, igual ao edit.
- `DomainException` no caminho sync: antes `report()` + ruido no log de excecoes; agora resultado Invalid esperado (sem report).
- `DomainException` dentro do job: antes queimava 3 tentativas + backoff 60/300s antes de `failed()`; agora terminal na primeira (marca FAILED).
- Exaustao do job: antes so PENDING->FAILED; agora DRAFT->FAILED tambem (rascunho orfao aparece como "Falhou" com `job_exhausted`).
- `markFailed` via `save()`: a transicao FAILED agora gera entrada de activity log (antes UPDATE cru sem evento) — benigna.

Testes existentes atualizados mecanicamente (nenhum assert de comportamento muda): `EmitCiotDeclarationTest` troca a dependencia injetada no job-handle (86/114/140/163/191, Fase 2); `test_cannot_enqueue_an_issued_ciot` migra para `enqueue()` (Fase 2); `test_failed_hook_marks_pending_ciot_as_failed` ganha caso DRAFT (Fase 2); `test_enqueueing_sets_the_ciot_pending_and_dispatches_the_job` migra para `enqueue()` (Fase 4).

## Decisoes em Aberto

> **Decidido em 2026-09-28:** as quatro recomendacoes abaixo foram ACEITAS pelo usuario — lock no lote agora (indice unique fica para decisao posterior), cancel/close permanecem modulos separados, `CiotEmissionNotice` em `app/Filament/Support/`, outcome mantem o nome `Invalid`.

1. **Indice unique para o guard D5 — agora ou depois?** Recomendado: lock na linha do lote agora e indice depois. MySQL nao tem partial index; unicidade real exigiria coluna gerada (`batch_id` non-null + status) e migration sensivel a dados existentes. O lock fecha a corrida entre os dois pontos de entrada atuais.
2. **Escopo de cancel/close.** Recomendado: manter `CancelCiot`/`CloseCiot` como modulos separados — espaco de outcomes diferente (so sucesso/rejeicao), HTTP-antes-do-lock deliberado, um call site cada; apenas o ritual de transicao foi trocado. Fundi-los no seam de emissao e gold-plating.
3. **Nome/placement do `CiotEmissionNotice`.** Recomendado: `app/Filament/Support/CiotEmissionNotice.php` — o diretorio ja abriga `ChecklistConciliationAction` e `IntegrationInboxItemPresentation`; e apresentacao, nao dominio (`app/Services/Ciot/` ficaria errado).
4. **Nome do outcome `Invalid`.** Recomendado: manter `Invalid`. `Rejected` colidiria com a semantica de rejeicao da ANTT (que e o `Failed` de negocio).

## Criterios de Conclusao

- Nenhuma coreografia copiada: cada um dos seis call sites reduz-se a criar/salvar/buscar + `emit()`/`enqueue()` + notice + redirect.
- D1-D6 mortos e provados por teste (parametrizados por outcome nos sites sync; regressao D3; guard de duplicado transacional; snapshot B119 no create).
- `handle()` e `bool $sync` nao existem mais; greps da tarefa 4.2 vazios.
- `tests/Feature/Services/Ciot/CiotCancelAndCloseTest.php` intocado e verde.
- Strings de notificacao, redirects, guards, hooks e configuracao de fila byte-identical nos caminhos cobertos.
- Suite completa (`php artisan test --compact`), `vendor/bin/pint --dirty --format agent` e `git diff --check` passam.
