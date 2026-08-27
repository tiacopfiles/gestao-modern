# Checklist de homologação — para o time financeiro

Este documento não usa termos técnicos. É um roteiro para quem vai testar o sistema novo de conciliação e decidir se ele está pronto para o dia a dia. Use junto com `GUIA_DEMONSTRACAO_LOCAL.md` (que explica como abrir o sistema em um computador de testes, sem mexer em nada real).

Para cada cenário abaixo, marque o que aconteceu e escreva um comentário se algo pareceu estranho, difícil de entender ou diferente do esperado.

## Como testar

O sistema roda numa cópia local, separada dos sistemas de Contas a Pagar e Contas a Receber que vocês já usam. Nada do que for feito aqui afeta os dados reais da empresa. Todos os nomes, valores e documentos são inventados só para teste.

## Cenários

| # | Cenário | O que fazer | Funcionou como esperado? | Comentário |
|---|---|---|---|---|
| 1 | Conta a pagar | Ver um título a pagar de teste na tela | ☐ Sim ☐ Não | |
| 2 | Conta a receber | Ver um título a receber de teste na tela | ☐ Sim ☐ Não | |
| 3 | Pagamento parcial | Conciliar apenas parte do valor de um título a pagar com uma transação bancária | ☐ Sim ☐ Não | |
| 4 | Recebimento parcial | Conciliar apenas parte do valor de um título a receber com uma transação bancária | ☐ Sim ☐ Não | |
| 5 | Transações iguais legítimas | Ver que duas transações bancárias com o mesmo valor e mesma data não se confundem uma com a outra | ☐ Sim ☐ Não | |
| 6 | Match simples | Ligar manualmente um título a um lançamento bancário (1 para 1) | ☐ Sim ☐ Não | |
| 7 | Match composto | Ligar um título a mais de uma transação bancária (ex.: pago em duas vezes) | ☐ Sim ☐ Não | |
| 8 | Divergência | Ver uma transação bancária sem título correspondente aparecer numa lista de pendências, sem sumir nem ser ignorada | ☐ Sim ☐ Não | |
| 9 | Candidato errado | O sistema sugere uma ligação entre título e banco que você acha errada — recusar essa sugestão | ☐ Sim ☐ Não | |
| 10 | Rejeição | Confirmar que, ao recusar uma sugestão, o sistema guarda o motivo e não apaga nada | ☐ Sim ☐ Não | |
| 11 | Justificativa | Escrever uma explicação para uma pendência que não tem solução automática (ex.: "aguardando confirmação do fornecedor") | ☐ Sim ☐ Não | |
| 12 | Fechamento | Fechar um período (um mês, uma conta) depois de revisar tudo | ☐ Sim ☐ Não | |
| 13 | Reabertura | Reabrir um período já fechado, escrevendo o motivo, e ver que o fechamento antigo continua guardado (não foi apagado) | ☐ Sim ☐ Não | |
| 14 | Histórico | Ver a lista de todos os fechamentos de uma conta, incluindo quando foram feitos e por quem | ☐ Sim ☐ Não | |

## Perguntas que só o financeiro pode responder

O sistema tem algumas decisões que **não foram tomadas automaticamente** — porque são decisões de negócio, não técnicas. Elas estão detalhadas (em linguagem técnica) em `PENDENCIAS_NEGOCIO_FINAIS.md`. Resumidas em linguagem simples:

1. Quem pode fechar um período? Pode ser qualquer pessoa autorizada, ou só quem cuida daquela conta específica?
2. Quem pode reabrir um período já fechado? A mesma pessoa que fechou pode reabrir sozinha, ou precisa ser outra pessoa?
3. Se sobrar alguma pendência sem solução, o sistema pode fechar mesmo assim, ou tem que resolver tudo antes?
4. Existe um prazo (por exemplo, até o dia 10 do mês seguinte) depois do qual não se pode mais fechar?
5. Dá para reabrir um mês de 6 meses atrás sem mais ninguém aprovar?
6. O fechamento precisa da confirmação de duas pessoas diferentes, ou uma pessoa sozinha já pode fechar?
7. O relatório do fechamento precisa sair em algum formato específico (PDF, Excel) para auditoria?

Nenhuma dessas perguntas tem resposta definitiva ainda — o sistema está funcionando com a opção mais cautelosa em cada caso (por exemplo: hoje, uma pendência sem solução **impede** o fechamento, e não existe prazo limite). Se o time financeiro quiser mudar esse comportamento, é só responder e a equipe técnica ajusta.

## Ao final do teste

Reúna todas as respostas deste checklist e entregue para a equipe técnica. Isso vira parte da decisão final sobre quando o sistema pode começar a ser usado de verdade, em paralelo com o que já existe hoje (nada do sistema atual será desligado enquanto isso).
