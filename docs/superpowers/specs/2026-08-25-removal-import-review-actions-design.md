# Acoes de Revisao de Importacoes

## Objetivo

Dar um caminho seguro para tratar excecoes da caixa de integracoes sem criar
registros incompletos ou aceitar dados que ainda nao foram validados.

## Comportamento

### Baixa sem registro

O fluxo de `Conciliar` continua aceitando somente a associacao com um registro
existente. Quando nenhum registro compativel for encontrado, o campo de
selecao deve ficar desabilitado, exibir uma explicacao e impedir o envio. Nao
sera criado um registro automaticamente nesse fluxo.

### Pedido de remocao

Itens de pedido de remocao terao tres decisoes distintas:

- `Aceitar importacao`: aplica todos os dados ja validados e o PDF candidato,
  podendo criar um novo registro somente quando todos os campos obrigatorios e
  o PDF estiverem validos.
- `Revisar seletivamente`: aplica apenas os campos e o PDF selecionados pelo
  usuario, usando o servico transacional existente.
- `Rejeitar importacao`: encerra a pendencia sem alterar o registro.

Itens sem dados obrigatorios ou sem PDF valido nao poderao ser aceitos. A tela
deve mostrar o motivo e manter disponivel a rejeicao.

### Reprocessamento

Falhas de preparacao ou falhas transitorias, incluindo `domain_error`, terao
`Tentar novamente`. A acao recoloca o item em `queued` e despacha o job
existente. O job e responsavel por baixar, validar, extrair e importar o PDF.

O reprocessamento nao deve duplicar itens nem registros e nao deve apagar um
resultado resolvido por outro worker.

## Limites

- Nao alterar a regra de no maximo 50 mensagens por sincronizacao.
- Nao criar registros no fluxo de baixa sem registro.
- Nao aceitar payload incompleto como se fosse sucesso.
- Nao remover o PDF atual antes da nova persistencia ser confirmada.

## Testes

Adicionar cobertura para:

- conciliacao sem opcoes, com envio bloqueado;
- visibilidade das acoes por tipo e estado do item;
- retry de `domain_error` e despacho do job;
- aceite completo com dados e PDF validos;
- bloqueio do aceite sem dados obrigatorios ou PDF;
- preservacao do comportamento seletivo, da rejeicao e da idempotencia.
