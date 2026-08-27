# ADR-010 — Conciliação sem efeitos financeiros automáticos

- Status: aceito
- Data: 2026-08-13

## Contexto

Confirmar que um fato bancário corresponde a um título é uma decisão diferente de dar baixa financeira. O núcleo já possui `title_settlements`, estados de título/parcela e fatos bancários imutáveis. Acoplar essas mudanças à primeira versão da conciliação criaria efeitos contábeis ainda sem regra de negócio aprovada para data de baixa, tarifa, diferença, estorno e fechamento.

## Decisão

Na Fase 4, confirmar ou desfazer um match altera somente as quatro tabelas modernas da conciliação e a trilha genérica de auditoria. O fluxo:

- não cria, atualiza nem remove `title_settlements`;
- não muda valor, saldo, status ou qualquer outro campo de `financial_titles` e `title_installments`;
- não atualiza nem exclui `bank_transactions`;
- não cria movimentos e não escreve em nenhuma tabela financeira legada;
- não infere tarifa, diferença, estorno ou data de liquidação.

O estado de conciliação exibido na interface é derivado exclusivamente da soma de alocações em matches `CONFIRMED`. Ele é independente do status financeiro original do título e das liquidações existentes. Desfazer apenas troca o match para `VOIDED`; não realiza estorno financeiro e não toca numa baixa que já exista por outro fluxo.

## Proteção do domínio

Essa separação mantém três fatos auditáveis e reversíveis em seus próprios limites:

1. o título informa o que deveria ser pago ou recebido;
2. a transação informa o que o banco registrou;
3. o match informa qual relação um operador confirmou.

Uma falha na conciliação não compromete produtores, títulos, pagamentos, recebimentos ou a importação bancária. A feature flag pode remover a nova superfície web imediatamente, sem alterar os fatos já armazenados e sem afetar os módulos existentes.

## Decisão futura necessária

Antes de qualquer automação de baixa, o negócio precisa aprovar, testar e documentar pelo menos:

- se todo match confirmado deve criar settlement ou se haverá uma confirmação adicional;
- qual data e origem devem compor a baixa;
- como tratar parcial, diferença, tarifa, juros, desconto, estorno e chargeback;
- como conciliar títulos já liquidados ou parcialmente liquidados;
- como reverter uma baixa sem apagar evidência;
- quais controles de fechamento e segregação de funções são obrigatórios.

Até essa decisão existir, `ManualReconciliationService` não pode chamar o serviço de settlement.

## Consequências

- A Fase 4 entrega rastreabilidade sem criar efeito financeiro implícito.
- Conciliação e liquidação podem divergir temporariamente, de modo explícito e observável.
- Relatórios futuros precisam distinguir status financeiro de status de conciliação.
- A automatização de baixa exige uma fase própria, homologação e novos testes de segurança.

