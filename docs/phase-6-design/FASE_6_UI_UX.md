# Fase 6 — UI/UX

- Status: **proposta, não implementada**. Nenhuma view real foi criada ou alterada.

## 1. Princípio: reaproveitar `/reconciliacao-v2`, não redesenhar

`resources/views/reconciliation-v2/` já estabelece um sistema visual consistente: `@extends('layouts.app')`, `record-hero` para o cabeçalho da unidade de trabalho, `panel table-panel` para blocos de conteúdo, `badge {success|warning|danger|info|neutral}` para status, `empty-state`/`empty-state small` para listas vazias, `filter-bar` para formulários de filtro, `@include('partials.pagination', ['paginator' => $x])` para paginação, e `@can('reconciliation:xxx')` para esconder ações sem permissão. A Fase 6 usa exatamente esse vocabulário. Nenhuma tela nova de Contas a Pagar/Receber ou do legado (`/conciliacoes`) é tocada.

## 2. Telas

### 2.1 Sessão aberta (`reconciliation-v2.show` — tela existente, extensão)

A tela `show.blade.php` atual já tem: cabeçalho (`record-hero`), resumo de matching (se habilitado), sugestões, divergências, match manual, histórico. A Fase 6 adiciona **uma seção nova** ao final, condicionada a `config('reconciliation.closing_enabled')` e a `@can('reconciliation:view')`:

```text
┌─ Fechamento ─────────────────────────────────────────────┐
│ Status: [badge] ABERTA / EM REVISÃO / FECHADA / REABERTA  │
│                                                             │
│ Se ABERTA/EM REVISÃO/REABERTA:                             │
│   @can('reconciliation:close')                             │
│   [Preparar fechamento →]  (leva à tela de pré-fechamento) │
│   @endcan                                                  │
│                                                             │
│ Se FECHADA:                                                │
│   Fechamento #<sequence_number> em <closed_at> por <ator>  │
│   hash <closure_hash, truncado com tooltip completo>       │
│   [Ver histórico de fechamentos →]                          │
│   @can('reconciliation:reopen')                             │
│   [Reabrir →]                                                │
│   @endcan                                                    │
└─────────────────────────────────────────────────────────────┘
```

Reaproveita o padrão de badge já usado para `session->status` (linha 8 de `show.blade.php` hoje mapeia `IN_REVIEW`→info/`OPEN`→warning); a Fase 6 só adiciona os dois novos valores (`CLOSED`→success, `REOPENED`→warning) ao mesmo `match`.

### 2.2 Pré-fechamento (`reconciliation-v2.closure.create` — nova)

Rota: `GET /reconciliacao-v2/sessoes/{session}/fechamento/novo`, permissão `reconciliation:close`.

```text
┌─ Preparar fechamento — Sessão #42 ─────────────────────────┐
│ Conta: <nome>          Período: 01/08/2026 – 31/08/2026    │
│                                                              │
│ Métricas (calculadas agora, ainda não persistidas):         │
│  · Transações bancárias: 128   · Crédito total: R$ 42.500,00│
│  · Débito total: R$ 8.200,00   · Títulos envolvidos: 96     │
│  · Valor conciliado: R$ 40.100,00                            │
│  · Valor não conciliado: R$ 2.400,00                          │
│                                                                │
│ Matches confirmados incluídos: 87   (tabela expansível)       │
│                                                                │
│ Checklist:                                                    │
│  ✅ Sem match em conflito                                     │
│  ✅ Sem sobreposição de período com outro fechamento          │
│  ⚠️  3 divergências OPEN (política atual: bloqueia)            │
│  ⚠️  2 candidatos PENDING não decididos                        │
│                                                                │
│ [Ver divergências →] [Ver candidatos →]                       │
│                                                                │
│ @if($readiness->ready)                                        │
│   [Confirmar fechamento] (leva à confirmação, §2.3)           │
│ @else                                                          │
│   Fechamento bloqueado até resolver os itens acima. (botão desabilitado, com tooltip listando os blockers) │
│ @endif                                                          │
└──────────────────────────────────────────────────────────────┘
```

Dados vêm de `ReconciliationClosureValidator` (readiness, blockers, warnings) e de uma consulta de métricas equivalente à que `ReconciliationClosureSnapshotBuilder` usaria — **calculada sob demanda, não persistida**, exatamente como o `READY_TO_CLOSE` da máquina de estados (`FASE_6_STATE_MACHINE.md` §1). Cada `blocker`/`warning` usa o mesmo componente visual `badge danger`/`badge warning` já usado nas filas de divergência.

### 2.3 Confirmação — não permitir fechamento acidental

O clique em "Confirmar fechamento" na tela de pré-fechamento **não fecha diretamente**. Ele abre uma confirmação explícita (modal ou tela intermediária, a decidir na implementação, mas nunca um único clique):

```text
┌─ Confirmar fechamento — Sessão #42 ─────────────────────────┐
│ Esta ação é reversível apenas por reabertura excepcional,     │
│ registrada e com motivo obrigatório.                          │
│                                                                  │
│ Você está fechando:                                              │
│  · 87 matches confirmados                                        │
│  · Período 01/08/2026 – 31/08/2026                                │
│  · Motor de matching: rules-v1                                     │
│                                                                       │
│ [Cancelar]              [Confirmar fechamento definitivamente]       │
└──────────────────────────────────────────────────────────────────────┘
```

`POST /reconciliacao-v2/sessoes/{session}/fechamento`, `reconciliation:close`. Segue o mesmo padrão de `voidMatch` (`VoidReconciliationMatchRequest` exige motivo/confirmação explícita antes de aceitar o `POST`) — nenhuma ação destrutiva/irreversível do sistema hoje é um único botão sem tela intermediária, e o fechamento não é exceção.

### 2.4 Histórico (`reconciliation-v2.closure.history` — nova)

Rota: `GET /reconciliacao-v2/sessoes/{session}/fechamentos`, permissão `reconciliation:view`.

```text
┌─ Histórico de fechamentos — Sessão #42 ─────────────────────┐
│ #  Status    Fechado em         Ator     Hash        │
│ 2  CLOSED    05/09/2026 10:15   fulano   a1b2c3…     → │
│ 1  REOPENED  01/09/2026 14:02   ciclana  9f8e7d…     → │
│    ↳ reaberto em 04/09/2026 09:30 por beltrano: "ajuste solicitado pelo financeiro" │
└─────────────────────────────────────────────────────────────┘
```

Cada linha `→` abre o detalhe do fechamento (`snapshot_payload` decodificado, lista de matches/exceptions/metrics incluídos — tabela, mesmo padrão de `matches.show`/`candidates.show` atuais). Reaproveita `panel table-panel` + `table-wrap table`. Comparação entre duas versões (§8 da máquina de estados) pode ser um link "comparar com #1" que abre um diff simples de duas colunas — não é crítico para o primeiro incremento e pode ficar em iteração futura, desde que os dados (`snapshot_payload` de cada fechamento) já estejam disponíveis desde o primeiro incremento.

### 2.5 Reabertura (`reconciliation-v2.closure.reopen` — nova)

Rota: `POST /reconciliacao-v2/sessoes/{session}/fechamentos/{closure}/reabrir`, permissão `reconciliation:reopen`.

```text
┌─ Reabrir fechamento #2 — Sessão #42 ─────────────────────────┐
│ Esta é uma operação excepcional e será auditada.               │
│                                                                    │
│ Motivo (obrigatório, 1–1000 caracteres):                          │
│ [________________________________________________]                │
│                                                                       │
│ [Cancelar]                              [Reabrir fechamento]         │
└─────────────────────────────────────────────────────────────────────┘
```

Mesmo padrão visual/de validação já usado em `voidMatch` (`VoidReconciliationMatchRequest`, motivo obrigatório de 1–1000 caracteres) — a Fase 6 reaproveita a mesma regra de validação, sem inventar um novo limite.

## 3. Navegação

```text
reconciliation-v2.show (sessão)
   └─ [Preparar fechamento] → reconciliation-v2.closure.create
         └─ [Confirmar]      → reconciliation-v2.closure.store (POST, sem tela própria — redireciona de volta para .show)
   └─ [Ver histórico]        → reconciliation-v2.closure.history
         └─ [Ver fechamento] → reconciliation-v2.closure.show (detalhe de uma linha)
              └─ [Reabrir]   → reconciliation-v2.closure.reopen (form) → POST reconciliation-v2.closure.reopen.store
```

## 4. O que NÃO muda

- `/reconciliacao-v2` (index de sessões), `create` (nova sessão), `match`/`showMatch`, `candidate`, `exception` — nenhuma alteração de layout ou comportamento.
- `/conciliacoes` (legado) — sem alteração.
- Contas a Pagar/Receber — sem alteração.
- Nenhuma tela nova fora do prefixo `reconciliacao-v2`.

## 5. Acessibilidade e estados vazios

Seguir o padrão já usado (`aria-label` em checkboxes de seleção, `empty-state`/`empty-state small` para tabelas vazias, `@disabled` em inputs sem disponibilidade). A tela de pré-fechamento usa o mesmo padrão de `empty-state` quando não há nada a mostrar (ex.: sessão sem nenhum match — cai em `CLOSURE_EMPTY_SESSION`, ver `FASE_6_STATE_MACHINE.md` §3).
