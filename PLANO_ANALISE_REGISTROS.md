# Plano de implementação: Análise de registros e avisos de erro no CT-e

> **Para execução por agentes:** usar `superpowers:executing-plans` e executar as tarefas com seus testes. As regras de negócio abaixo foram definidas com o usuário. A implementação foi autorizada e concluída neste workspace, preservando as alterações locais anteriores.

**Goal:** adicionar o status Análise e aproveitar a integração de e-mails existente para entregas sem CT-e e avisos da Copart sobre CT-e emitido incorretamente.

**Architecture:** reutilizar `Register`, `IntegrationInboxItem`, a sincronização Microsoft Graph e as ações do Filament. Separar a leitura do aviso de erro, sua aplicação ao registro e a decisão manual; preservar a confirmação de entrega independentemente do status. A autorização de um novo CT-e escolhido pelo usuário encerra a análise e considera a entrega já reconhecida.

**Tech Stack:** PHP 8.3, Laravel 12, Filament 3, Livewire 3, PHPUnit 11 e Laravel Pint.

**Registro:** 15/10/2026, America/Sao_Paulo. Atualizado com as respostas do usuário sobre a referência financeira e a inclusão exclusiva de registros Entregues no lote de pagamento. Implementação concluída em aplicação e testes; não foram necessárias novas migrações ou alterações de dependências para esta feature.

## 1. Regras financeiras definidas

**DEFINIDO — manter a data original da baixa de entrega no pátio como referência para o lote de pagamento, inclusive quando o CT-e válido é emitido posteriormente.** Não mudar a referência para a data do novo CT-e.

**DEFINIDO — somente registros com status Entregue podem entrar no lote de pagamento.** Um registro em Análise fica fora do lote, mesmo com a baixa de entrega já reconhecida. Após passar para Entregue, sua referência continua sendo a data original da baixa, respeitando os filtros e as janelas atuais.

Cenário informado pelo usuário:

1. Registro tem um CT-e emitido.
2. Chega um aviso de erro verdadeiro no documento; o registro entra em Análise.
3. A baixa de destino chega antes de haver um CT-e válido; guardar a confirmação de entrega e manter Análise.
4. O registro pode permanecer assim por algum tempo, até o usuário incluí-lo em um novo lote de CT-e.
5. Com o novo CT-e autorizado, o registro passa diretamente para Entregue.
6. A referência para seu enquadramento no lote de pagamento continua sendo a data original da baixa.

O usuário também determinou aproveitar o mecanismo manual existente de retirada e adiamento para o próximo lote. Não criar recuperação automática de janelas antigas como solução para esse cenário.

**Comportamento atual a preservar:** `CreatePaymentBatchForWindow` seleciona registros Entregues dentro de uma janela e `PaymentBatchRun::processed_at` impede gerar novamente uma janela processada. `RemovePaymentBatchItem` permite retirar um item já incluído cujo registro esteja Entregue; marca `payment_deferred_at` para inclusão futura. Manter esse mecanismo e as condições atuais de geração, retirada e confirmação de pagamento.

**Limitação documentada:** se o registro ficar em Análise até depois do processamento da janela original e nunca tiver entrado em lote algum, a transição para Entregue, sozinha, não garante sua inclusão futura. A retirada manual atual pressupõe que o item já esteja em um lote. Não prometer reinclusão automática nesse caso e não criar uma regra nova de recuperação ou um novo mecanismo de adiamento nesta feature; isso exigiria uma decisão específica posterior. Esta observação não altera a regra definida de data original e seleção somente de Entregues.

Preservar `delivery_confirmed_at` como a confirmação original de entrega e referência financeira, no padrão atualmente usado pelo sistema. Não criar uma segunda data financeira para este fluxo, não sobrescrever a entrega com a data do novo CT-e e não usar `updated_at`. Reutilizar `payment_deferred_at` para o adiamento manual.

Decisões registradas:

- [x] Registrar a data/evento financeiro definido pelo usuário: baixa de entrega original.
- [x] Registrar a preferência pelo adiamento manual existente, sem recuperação automática de janelas.
- [x] Confirmar o critério de seleção: somente Entregues; Análise fica fora dos lotes.
- [x] Completar os critérios de seleção e os testes da tarefa 6 com essa confirmação.
- [x] Implementação solicitada pelo usuário e concluída.

## 2. Regras de negócio consolidadas

### Status e entrega

O novo status visível será **Análise**, usado para entrega sem CT-e e erro comunicado pela Copart. O motivo deve continuar distinguível no histórico da integração.

| Evento | Efeito no registro |
| --- | --- |
| Baixa reconhecida sem CT-e autorizado | Guardar a entrega e mudar para Análise. |
| Aviso válido de erro em um CT-e associado ao registro | Guardar o status anterior, acrescentar o erro às observações e mudar para Análise. |
| Usuário reconhece que o erro é verdadeiro | Manter Análise; reconhecer o aviso não equivale a resolver o problema. |
| Baixa chega durante Análise | Guardar a entrega e manter Análise. |
| Usuário altera o status manualmente | Aplicar sua decisão e registrar a resolução da ocorrência correspondente. |
| Usuário inclui o registro em um lote de CT-e | Manter Análise enquanto o novo CT-e aguarda autorização. |
| Novo CT-e autorizado, sem entrega reconhecida | Mudar para Em nota fiscal. |
| Novo CT-e autorizado, com entrega reconhecida | Mudar diretamente para Entregue, preservando a data original da entrega. |
| Usuário descarta o aviso como falso positivo, sem entrega reconhecida | Resolver esse aviso; se não houver outro erro aberto, recuperar o status anterior à análise. |
| Usuário descarta o aviso como falso positivo, com entrega reconhecida | Resolver esse aviso; se não houver outro erro aberto, mudar para Entregue, aproveitando a baixa já recebida. |
| Mesmo aviso recebido novamente | Não repetir observações, não substituir o status anterior e não reabrir ocorrência resolvida. |
| Geração de lote de pagamento com registro ainda em Análise | Não incluir esse registro, mesmo com entrega reconhecida. |
| Geração de lote de pagamento após passar para Entregue | Aplicar as regras atuais com a data original da baixa. |

Após um falso positivo descartado antes da baixa, uma baixa futura volta a seguir o fluxo normal. A mudança por emissão ocorre na autorização do documento, conforme o fluxo atual; criar ou aprovar o lote não comprova que o CT-e foi autorizado.

A alteração manual deve permanecer disponível pelo fluxo atual de status. Ao sair de Análise, concluir o acompanhamento da ocorrência para que ela não continue aparecendo como problema aberto. A autorização do novo CT-e também conclui esse acompanhamento. Preservar a distinção entre reconhecimento do aviso e resolução do registro.

### Identificação do e-mail

- Remetente confirmado: `remocao@copart.com.br`, já usado pela integração.
- Assunto de referência: `COPART - Erro no Documento Número 3014`.
- Extrair do corpo o número após o texto de identificação do documento e a descrição após `Mensagem de Erro:`.
- Comparar os números do assunto e do corpo; usar a associação do CT-e ao registro, e não uma placa presumida.
- O número isolado não é uma chave global no banco. Se a consulta não identificar um único documento/registro compatível, abrir pendência manual sem alterar registros por aproximação.
- Tolerar variações de espaços, quebras de linha, caixa, acentos e corpo HTML. Guardar a mensagem legível, sem assinatura, rodapé ou instruções posteriores de cancelamento.
- Não limitar o reconhecimento à frase de comitente incorreto; essa é uma das descrições possíveis de erro.
- O aviso inicia a avaliação humana. Não corrigir a seguradora, cancelar documentos fiscais ou gerar um lote automaticamente.
- Avisos sem associação segura podem ser encerrados explicitamente após conferência manual, registrando usuário e data sem alterar registros. A ação não pode ser usada para encerrar uma análise associada.

Trecho de referência para as observações, preservando o conteúdo anterior:

```text
Identificamos um problema na validação dos dados do documento de transporte número:

3014
- Mensagem de Erro:

Erro na validação do documento de Transporte. Comitente que está na nota não é o mesmo comitente que está no cadastro do veículo.
```

### Duplicados

Reutilizar a proteção existente por `source + external_id` para reprocessamento da mesma mensagem. Para um novo e-mail com o mesmo aviso, comparar o documento associado e a mensagem de erro normalizada, sem depender do horário de recebimento. Manter o texto original para apresentação.

Usar a ocorrência original como referência tanto enquanto aberta quanto após sua resolução. Um novo CT-e tem outra identidade e pode receber seu próprio aviso. Preservar o histórico que sustenta essa comparação: a limpeza atual exclui integrações resolvidas após 30 dias e não deve apagar a ocorrência canônica necessária à deduplicação. Mensagens duplicadas podem continuar sujeitas à limpeza normal.

### Delimitação confirmada pelo usuário

- O aviso se refere a um CT-e que já foi emitido. Não adicionar um mecanismo específico para a hipótese de erro chegando antes da emissão daquele documento.
- Não adicionar tratamento especial para remover registros de lotes de pagamento já formados. O fluxo informado considera que o aviso chega antes da formação desses lotes.
- A data financeira permanece a baixa de entrega original e somente Entregues entram no pagamento; manter o adiamento manual e as janelas existentes, conforme a seção 1.
- Não alterar dependências, redesenhar a tela de registros ou refatorar subsistemas fora desse fluxo.

## 3. Pontos do código existentes

| Arquivo | Responsabilidade e impacto |
| --- | --- |
| `app/Enums/RegisterStatusEnum.php` | Status, traduções, opções e cores; incluir Análise. |
| `app/Models/Register.php` | Enum do status, `notes`, `delivery_confirmed_at` e relações com documentos/integrações. |
| `app/Services/MicrosoftGraph/ProcessChecklistEmail.php` | Identifica o registro e registra `missing_authorized_cte`; hoje não muda para Análise nem confirma a entrega nesse ramo. |
| `app/Services/MicrosoftGraph/RemovalRequests/RemovalRequestMessageRouter.php` | Encaminha pedidos de remoção e checklists; incluir o aviso de erro sem alterar a coleta do Graph. |
| `app/Models/IntegrationInboxItem.php` | Dados extraídos, status anterior, reconhecimento, resolução e consultas de pendências. |
| `app/Services/MicrosoftGraph/ResolveIntegrationInboxItem.php` | Conciliação atual de entrega; ajustar a interação com Análise. |
| `app/Services/MicrosoftGraph/AcknowledgeIntegrationAlert.php` | Reconhecimento de checklist; manter separado de uma resolução de erro no CT-e. |
| `app/Filament/Support/ChecklistConciliationAction.php` | Ação atual de conciliação; restringir explicitamente a checklists ao incluir outro tipo de mensagem. |
| `app/Filament/Resources/IntegrationInboxItemResource.php` | Lista, filtros, detalhes e ações; apresentar o novo tipo de aviso. |
| `app/Filament/Resources/RegisterResource.php` | Status manual, ordenação, seleção para lote e observações; integrar o encerramento manual da análise. |
| `app/Services/Cte/MarkRegisterInvoicedForCte.php` | Hoje sempre leva o registro a Em nota fiscal; considerar a entrega já confirmada. |
| `app/Services/Cte/RecordDirectCteResult.php` e `app/Services/Cte/CteDocumentRecoveryService.php` | Chamadores da atualização na primeira autorização; preservar a proteção contra reaplicação em tentativas repetidas. |
| `app/Console/Commands/CleanupIntegrationInboxItems.php` | Retenção das integrações resolvidas e preservação da ocorrência canônica do erro. |
| `app/Services/Payments/CreatePaymentBatchForWindow.php`, `app/Services/Payments/RemovePaymentBatchItem.php` e `app/Services/Payments/GeneratePendingPaymentBatches.php` | Preservar referência pela baixa, seleção somente de Entregues, janelas e adiamento manual existentes. |

Há alterações locais preexistentes em registros, planilha, estilos, provider, migrações e testes. A implementação deverá incorporá-las sem reverter ou incluir alterações alheias por acidente. Este plano fica na raiz porque `docs/` está ignorado no repositório.

## 4. Desenho técnico proposto

Recomendação: um único status Análise, aproveitando as ocorrências da integração para registrar os motivos. Dois status distintos aumentariam as opções de operação para a mesma fila de revisão; guardar tudo apenas em observações dificultaria identificar duplicados e separar reconhecimento de resolução.

- Adicionar `ANALYSIS = 'analysis'` ao enum, seguindo a convenção atual do projeto, com rótulo em português Análise e cor de alerta existente.
- Adicionar `message_type = 'cte_error'` à integração. Usar `extracted_data` para `cte_document_id`, `cte_number`, `error_message` e `error_fingerprint`; usar os campos existentes de status anterior, reconhecimento e resolução.
- Criar `CteErrorEmailParser` para leitura e validação do aviso e `ProcessCteErrorEmail` para localização, deduplicação e aplicação da ocorrência. Ambos ficam em `app/Services/MicrosoftGraph/`.
- Criar `ResolveCteErrorAnalysis` no mesmo diretório para as decisões sobre esse aviso; manter a ação de reconhecimento separada da conclusão efetiva. Centralizar o fechamento do acompanhamento para que edição manual e autorização não deixem ocorrências abertas.
- Criar `ReviewCteErrorAction` em `app/Filament/Support/` para reconhecer o problema ou descartar o falso positivo usando ações autenticadas do Filament.
- Aplicar criação da ocorrência, deduplicação e alterações do registro na mesma transação, bloqueando o registro antes da consulta de duplicados. Reutilizar o índice único existente para a identidade do e-mail.
- Guardar o status anterior na entrada inicial; uma nova baixa durante a análise não deve substituir essa referência por Análise.
- Distinguir o acompanhamento de entrega sem CT-e do acompanhamento de erro em CT-e, reutilizando o tipo e os alertas atuais de checklist.
- Reaproveitar `delivery_confirmed_at`, `payment_deferred_at` e as relações existentes. A resposta sobre a data dispensa um novo campo financeiro para este fluxo.
- Reutilizar a seleção e confirmação de reemissão de `CreateCteEmissionBatch`; preservar os procedimentos fiscais atuais de correção/cancelamento.

## 5. Plano de execução

### Tarefa 1 — status e apresentação em registros

**Modificar:** `app/Enums/RegisterStatusEnum.php`, `app/Filament/Resources/RegisterResource.php` e, se necessário para opções da planilha, `app/Filament/Resources/RegisterResource/Pages/ListRegisters.php`.

**Criar teste:** `tests/Unit/Enums/RegisterStatusLabelTest.php`.

**Atualizar teste:** `tests/Feature/Filament/RegisterResourceTest.php`.

- [x] Cobrir rótulos, cor, opções, filtro e seleção manual do novo status.
- [x] Executar os testes focados e conferir a falha esperada antes da implementação.
- [x] Incluir o enum e sua apresentação; dar posição explícita ao novo status na ordenação atual.
- [x] Executar individualmente os dois arquivos de teste alterados e revisar o diff.

### Tarefa 2 — leitura e processamento do aviso de erro

**Criar:** `app/Services/MicrosoftGraph/CteErrorEmailParser.php`, `app/Services/MicrosoftGraph/ProcessCteErrorEmail.php`, `tests/Unit/MicrosoftGraph/CteErrorEmailParserTest.php`, `tests/Feature/MicrosoftGraph/ProcessCteErrorEmailTest.php`.

**Modificar:** `app/Services/MicrosoftGraph/RemovalRequests/RemovalRequestMessageRouter.php`, `app/Models/IntegrationInboxItem.php`, `app/Console/Commands/CleanupIntegrationInboxItems.php`.

**Atualizar testes:** `tests/Feature/MicrosoftGraph/RemovalRequestMessageRouterTest.php`, `tests/Feature/MicrosoftGraph/SyncChecklistEmailsTest.php`, `tests/Feature/Console/CleanupIntegrationInboxItemsTest.php`.

- [x] Cobrir o exemplo do CT-e 3014, outros erros, HTML e normalização, remetente inválido, número divergente, texto incompleto e associação ausente/ambígua.
- [x] Cobrir manutenção das observações anteriores, primeiro status anterior preservado e ausência de alterações ao não localizar uma correspondência única.
- [x] Cobrir reprocessamento pelo mesmo ID e novo ID com o mesmo CT-e/erro, inclusive após resolução; nenhum deles deve repetir o efeito.
- [x] Executar cada arquivo novo/alterado antes e depois da implementação correspondente.
- [x] Implementar leitura, roteamento e aplicação transacional reutilizando o formato atual das mensagens.
- [x] Preservar ocorrências canônicas na limpeza e testar que duplicados continuam removíveis e os tipos existentes mantêm sua retenção.

### Tarefa 3 — baixa de entrega e Análise

**Modificar:** `app/Services/MicrosoftGraph/ProcessChecklistEmail.php`, `app/Services/MicrosoftGraph/ResolveIntegrationInboxItem.php`, `app/Services/MicrosoftGraph/AcknowledgeIntegrationAlert.php`, `app/Filament/Support/ChecklistConciliationAction.php`.

**Atualizar testes:** `tests/Feature/MicrosoftGraph/ProcessChecklistEmailTest.php`, `tests/Feature/Services/ResolveIntegrationInboxItemTest.php`, `tests/Feature/Services/AcknowledgeIntegrationAlertTest.php`, `tests/Feature/Models/RegisterDeliveryConfirmationTest.php`.

- [x] Cobrir entrega sem CT-e levando a Análise e guardando a confirmação no padrão atual do sistema.
- [x] Verificar a ausência real de CT-e antes da decisão automática, inclusive quando o status legado for Em nota fiscal: hoje esse status desvia da verificação de ausência do documento.
- [x] Cobrir entrega durante análise por erro, entrega já confirmada e checklists repetidos; manter Análise e a primeira confirmação de entrega.
- [x] Adaptar a conciliação sem fazer do simples reconhecimento do aviso uma saída de Análise. Uma decisão explícita de status pelo usuário continua permitida.
- [x] Executar individualmente os arquivos afetados e confirmar que o fluxo normal de baixa com CT-e válido continua funcionando.

### Tarefa 4 — revisão pelo usuário e conclusão manual

**Criar:** `app/Services/MicrosoftGraph/ResolveCteErrorAnalysis.php`, `app/Filament/Support/ReviewCteErrorAction.php`, `tests/Feature/Services/ResolveCteErrorAnalysisTest.php`.

**Modificar:** `app/Models/IntegrationInboxItem.php`, `app/Filament/Resources/IntegrationInboxItemResource.php`, `app/Filament/Resources/RegisterResource.php` e os pontos efetivos de alteração manual de status, incluindo edição e ações em lote.

**Atualizar testes:** `tests/Feature/Filament/IntegrationInboxItemResourceTest.php`, `tests/Feature/Filament/ActionableIntegrationsTest.php`, `tests/Feature/Filament/RegisterResourceTest.php`.

- [x] Cobrir reconhecimento de erro verdadeiro mantendo Análise e acompanhamento ainda não resolvido.
- [x] Cobrir falso positivo com e sem entrega reconhecida; registrar usuário e decisão sem apagar o histórico do aviso.
- [x] Cobrir baixa normal após descarte de falso positivo e tentativa de repetir a resolução.
- [x] Cobrir saída manual pelo fluxo existente sem deixar pendência aberta; edição apenas de observações não deve resolver a análise.
- [x] Mostrar número, erro e motivo no recurso de integrações; incluir filtro do novo tipo e sua presença nas consultas de pendências.
- [x] Restringir as ações por tipo de mensagem e aplicar autorização no servidor, usando os padrões existentes de Filament/Livewire.
- [x] Executar individualmente os testes criados/alterados.

### Tarefa 5 — autorização do novo CT-e

**Modificar:** `app/Services/Cte/MarkRegisterInvoicedForCte.php` e integrar a conclusão do acompanhamento criada na tarefa 4. Alterar chamadores somente se necessário.

**Atualizar testes:** `tests/Feature/Services/Cte/EmitCteDocumentTest.php`, `tests/Feature/Services/CteDocumentRecoveryTest.php`, `tests/Feature/Services/CreateCteEmissionBatchTest.php`.

- [x] Cobrir seleção de registro em Análise para reemissão no fluxo existente.
- [x] Cobrir criação/aprovação do lote e falha/rejeição da emissão mantendo Análise.
- [x] Cobrir autorização sem entrega levando a Em nota fiscal e autorização com entrega levando a Entregue, preservando a data e concluindo o acompanhamento.
- [x] Cobrir o mesmo resultado no caminho de recuperação/reconciliação de uma primeira autorização confirmada.
- [x] Preservar os testes que impedem reaplicar uma autorização antiga e sobrescrever um status manual em uma nova tentativa.
- [x] Executar individualmente os três arquivos afetados.

### Tarefa 6 — pagamento pela baixa original e adiamento manual

**Revisar e preservar:** `app/Services/Payments/CreatePaymentBatchForWindow.php`, `app/Services/Payments/RemovePaymentBatchItem.php` e as ações correspondentes em `app/Filament/Resources/PaymentBatchResource/RelationManagers/ItemsRelationManager.php`. Conferir a integração com `app/Services/Payments/GeneratePendingPaymentBatches.php` e `app/Services/Payments/ConfirmPaymentBatch.php`. O critério atual de aceitar somente Entregues já corresponde à decisão; não ampliar a seleção ou a retirada para Análise. Manter datas e janelas atuais, sem criar uma referência financeira separada.

**Atualizar testes:** `tests/Feature/Services/CreatePaymentBatchForWindowTest.php`, `tests/Feature/Services/RemovePaymentBatchItemTest.php`, `tests/Feature/Services/GeneratePendingPaymentBatchesTest.php` e `tests/Feature/Filament/PaymentBatchResourceTest.php`, conforme os caminhos efetivamente alterados.

- [x] Cobrir registro em Análise ficando fora do lote, inclusive com `delivery_confirmed_at` preenchido ou marcador de adiamento anterior.
- [x] Cobrir entrega anterior ao CT-e válido: a janela de referência permanece determinada pela baixa original.
- [x] Cobrir autorização do CT-e após a entrega: quando o registro estiver Entregue e sua janela ainda for elegível, a inclusão usa a baixa original, e não a autorização do novo CT-e.
- [x] Cobrir retirada manual com `payment_deferred_at` e inclusão uma única vez no próximo lote elegível, sem alterar a data da baixa nem duplicar itens.
- [x] Preservar e documentar o comportamento de janela original já processada: sem adiamento anterior, não há reinclusão automática e um item ausente não pode ser retirado. Não introduzir recuperação automática de janelas.
- [x] Garantir que os pagamentos e adiamentos já existentes continuam funcionando.
- [x] Executar os arquivos de teste efetivamente alterados. Não implementar retirada automática de lotes já formados.

### Tarefa 7 — revisão e verificação final da implementação

- [x] Revisar os diffs por tarefa e conferir todos os comportamentos da seção 2.
- [x] Usar `list-artisan-commands` do Boost antes de geradores/comandos Artisan; criar classes e testes PHPUnit com os geradores e `--no-interaction`.
- [x] Usar Boost `search-docs` antes das mudanças relacionadas ao ecossistema Laravel.
- [x] Executar individualmente os arquivos de teste criados/alterados indicados em cada tarefa, usando `php artisan test --compact`, por exemplo `php artisan test --compact tests/Feature/MicrosoftGraph/ProcessChecklistEmailTest.php`. As verificações devem usar fakes/mocks para e-mail e autorização fiscal.
- [x] Executar `vendor/bin/pint --dirty --format agent`; conferir que não houve formatação inadvertida de trabalho local preexistente. Se houver ajuste funcional após testes, repetir somente os testes afetados.
- [x] Informar os testes executados e os resultados, e oferecer a execução da suíte completa ao usuário, sem executá-la automaticamente.

## 6. Critérios para retomada

Implementação autorizada pelo usuário e concluída. As regras financeiras permanecem: data da baixa original, seleção somente de Entregues e adiamento manual existente. A limitação de janelas já processadas descrita na seção 1 foi preservada.

Validação: 25 arquivos de testes afetados, com 261 testes e 1.426 assertions aprovados; testes de e-mail e autorização fiscal usam fakes/mocks, sem transmissão real. Laravel Pint (`vendor/bin/pint --dirty --format agent`) e `git diff --check` passaram. Revisões de especificação e qualidade aprovaram as correções. A suíte completa foi executada posteriormente com autorização do usuário: 1.186 testes passaram, 1 foi ignorado e 8.914 assertions foram verificadas, sem falhas. Houve um aviso de depreciação de metadados em doc-comments em `PtbrNumericTest`, referente ao PHPUnit 12.
