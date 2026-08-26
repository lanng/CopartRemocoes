# Dashboard de Integracoes Acionaveis

## Objetivo

Tornar imediatamente visiveis as integracoes que exigem intervencao humana,
distinguindo baixas de entrega de inclusoes de registro e concentrando as
pendencias prioritarias na pagina inicial do painel.

## Nomenclatura

O recurso atualmente chamado `Baixas por e-mail` passara a se chamar
`Integracoes por e-mail`, pois reune dois fluxos:

- `Baixa de entrega`: mensagem de checklist que confirma uma entrega.
- `Inclusao de registro`: pedido de remocao que cria ou atualiza um registro.

A listagem exibira uma coluna compacta `Tipo`, usando badges visualmente
distintos para os dois fluxos.

## Fonte de verdade

`IntegrationInboxItem` continuara sendo a fonte de verdade. O model tera uma
semantica unica para informar se um item exige acao do usuario. Essa mesma
regra sera usada pela caixa de integracoes, pelo widget e pelos contadores,
evitando consultas com criterios divergentes.

Exigem acao:

- checklist pendente sem registro correspondente;
- entrega processada com `missing_authorized_cte`;
- entrega processada com `unexpected_status`;
- pedido de remocao pendente com falha de processamento;
- pedido de remocao aguardando revisao de campos ou PDF;
- pedido de remocao com alerta de FIPE zerada ou frete alterado.

Itens conciliados, revisados, rejeitados ou reconhecidos permanecem no
historico, mas deixam de exigir acao.

## Widget inicial

A pagina inicial exibira um widget largo chamado `Acoes necessarias`, limitado
a 10 itens. A lista sera unica, sem separar os fluxos em secoes.

Cada linha mostrara:

- tipo da integracao;
- ID e placa do veiculo;
- ocorrencia;
- data e hora de recebimento;
- acao principal disponivel.

As acoes poderao ser `Visualizar`, `Conciliar`, `Revisar`, `Tentar novamente`
ou `Reconhecer`, conforme o estado do item. O widget reutilizara os servicos e
as rotas existentes; nao implementara regras proprias de conciliacao ou
importacao.

O rodape tera o link `Ver todas as integracoes` para a listagem completa.

## Prioridade

Os itens serao ordenados por prioridade e, dentro da mesma prioridade, pelo
mais antigo primeiro:

1. Entrega sem CT-e.
2. Inclusao com erro de processamento.
3. Entrega fora do fluxo.
4. Revisao ou conciliacao pendente.
5. Alerta de FIPE zerada ou frete alterado.

## Reconhecimento

Alertas processados de entrega sem CT-e ou fora do fluxo terao uma acao
explicita `Reconhecer`. Como esses itens ja usam `resolved_at` para registrar o
fim do processamento do e-mail, serao adicionados `acknowledged_by` e
`acknowledged_at` para registrar separadamente quem reconheceu o alerta e em
qual momento. A acao nao alterara o registro associado nem apagara o alerta ou
os dados de auditoria.

O reconhecimento de alertas de inclusao continuara seguindo a mesma regra.
Somente itens ainda nao reconhecidos aparecerao no widget.

## Listagem completa

A listagem permanecera como historico de todas as integracoes. Ela exibira o
tipo em uma coluna propria e manterá filtros por tipo, situacao e alerta. Os
rotulos de navegacao, titulo, singular e plural serao atualizados para
`Integracoes por e-mail`.

## Estados vazios e falhas

Quando nao houver itens acionaveis, o widget mostrara um estado positivo e
discreto informando que nao existem acoes pendentes. Se uma acao falhar, o
item permanecera na lista e o painel exibira a notificacao de erro do fluxo
existente, sem marcar o item como resolvido.

## Testes

Adicionar cobertura para:

- rotulos e badges dos dois tipos na listagem;
- classificacao de todos os estados acionaveis;
- exclusao de itens resolvidos ou reconhecidos;
- ordem de prioridade e desempate por data;
- limite de 10 itens e link para a listagem completa;
- reconhecimento de alertas de entrega em campos proprios de usuario e data;
- visibilidade das acoes adequadas por tipo e estado;
- estado vazio do widget;
- ausencia de consultas N+1 ao carregar registros associados.
