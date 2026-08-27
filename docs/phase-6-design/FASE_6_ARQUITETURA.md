# Fase 6 — Arquitetura do fechamento e governança

- Status: **proposta, não implementada**
- Pré-condição para implementação: `PACOTE_CONTINUACAO_FASE_5_5C_GO.md` (ou pacote equivalente) declarando `GO PARA FASE 6`
- Escopo deste documento: decisões arquiteturais que podem ser tomadas **antes** da homologação MariaDB terminar, para que a implementação comece imediatamente após o GO

Este documento não altera `app/`, `database/migrations/`, `routes/` ou testes existentes. É especificação.

---

## 1. Objetivo

Projetar um fechamento de conciliação por conta/período que responda de forma auditável e reproduzível:

- qual conta e período foram fechados;
- quando e por quem;
- quais matches e divergências pertenciam ao fechamento;
- quais valores foram consolidados;
- qual configuração (versão do motor, pesos, thresholds) estava vigente;
- se o fechamento pode ser reproduzido depois, mesmo que dados atuais mudem;
- quem reabriu, quando e por quê.

## 2. Princípio central: fechamento reproduzível

> Um fechamento histórico não pode mudar silenciosamente porque algum dado atual mudou.

Isso já é parcialmente garantido pelo modelo das Fases 1–5:

- `reconciliation_matches` nunca sofre `UPDATE` nos campos de confirmação (`confirmed_by`, `confirmed_at`, `method`) — `void` apenas adiciona campos, não os substitui (ADR-009);
- `reconciliation_exceptions` e `reconciliation_candidates` carregam `signature_hash` + `engine_version`, e regenerar nunca apaga histórico nem reabre estados terminais (ADR-011, ADR-012);
- `audit_events` registra `before_state`/`after_state` imutáveis por evento.

A Fase 6 estende esse princípio para uma **unidade de agregação nova**: o fechamento. Ele precisa sobreviver a:

- void tardio de um match que pertencia ao fechamento (o void em si já é permitido pelo domínio — a Fase 6 decide se isso é bloqueado *depois* do fechamento, ver §7 do modelo de estados);
- mudança de config (`config/reconciliation_matching.php`) entre o fechamento e uma consulta futura;
- nova versão do motor (`engine_version`);
- reaberturas e novos fechamentos do mesmo período.

## 3. Decisão: snapshot vs. referências vs. híbrido

### Opção A — Snapshot completo
Copiar integralmente títulos, parcelas, transações, matches e alocações para tabelas de fechamento no momento do `close`.

- Auditabilidade: máxima (nada depende de estado externo).
- Volume: alto — duplica dados já persistidos e imutáveis (matches já não mudam após confirmação).
- Simplicidade: baixa — exige serializar e manter em sincronia estruturas complexas (títulos parcelados, alocações N:N).
- Reprodução histórica: perfeita, mas redundante com o que já é imutável.
- Imutabilidade: fácil de garantir na cópia, mas não adiciona proteção real onde o original já é imutável.
- MariaDB 10.1: sem tipo `JSON` nativo; snapshot precisaria ser `LONGTEXT` estruturado (igual a `evidence`) ou dezenas de linhas por tabela.
- Rollback/reopen: barato de reverter (a cópia não afeta o original), mas caro de manter coerente após reabertura + novas mudanças.

### Opção B — Apenas referências + versão/hash
Guardar somente IDs de matches/exceptions incluídos, mais um hash calculado sobre o estado vigente no momento do fechamento.

- Auditabilidade: depende de os registros originais nunca mudarem. Isso é majoritariamente verdade (ADR-009/011/012), mas não é garantia estrutural — um `UPDATE` futuro em `reconciliation_match_titles.allocated_amount`, por exemplo, não é hoje impedido por nenhuma constraint, apenas por convenção de código.
- Volume: mínimo.
- Simplicidade: alta.
- Reprodução histórica: funciona enquanto os originais não mudam; se mudarem, a reprodução fica errada silenciosamente — **viola o princípio central**.
- Imutabilidade: fraca, pois depende inteiramente de disciplina de código em toda a base, presente e futura.
- MariaDB 10.1: trivial.
- Rollback/reopen: simples.

### Opção C — Híbrido (recomendado)

- Tabelas de junção (`reconciliation_closure_matches`, `reconciliation_closure_exceptions`) guardam a **referência** (FK) mais uma **cópia mínima e imutável** dos campos que definem o resultado financeiro daquela linha no momento do fechamento (`captured_status`, `captured_total_amount`, `captured_type`). Isso é o mesmo padrão já usado em `reconciliation_exceptions.evidence` e `reconciliation_candidates.evidence`: não duplica tudo, mas fixa o que importa para o veredito.
- Um `snapshot_payload` (`LONGTEXT`) consolidado guarda a lista canonizada e ordenada de todas as linhas incluídas, a configuração vigente (`engine_version`, pesos relevantes, thresholds) e as métricas agregadas — o material sobre o qual o `closure_hash` é calculado (§4).
- Métricas vão para uma tabela própria (`reconciliation_closure_metrics`) em vez de colunas fixas, porque a Fase 6 ainda não define todas as métricas que o negócio vai exigir (§19) — ver `FASE_6_PERGUNTAS_NEGOCIO.md`.

**Avaliação:**

| Critério | A – Snapshot completo | B – Só referência | C – Híbrido |
|---|---|---|---|
| Auditabilidade | Máxima | Frágil | Alta |
| Volume | Alto | Mínimo | Baixo/médio |
| Simplicidade | Baixa | Alta | Média |
| Reprodução histórica | Garantida | Não garantida | Garantida (via hash) |
| Imutabilidade | Forte (na cópia) | Fraca | Forte, e fiscalizável (hash detecta violação) |
| MariaDB 10.1 | Difícil sem JSON | Trivial | Viável com `LONGTEXT` (mesmo padrão já usado) |
| Rollback/reopen | Caro de manter | Simples | Simples (closure é apenas mais uma linha imutável) |

**Recomendação: Opção C.** É a única que cumpre o princípio central sem duplicar integralmente dados que já são, por design, imutáveis nas Fases 1–5, e sem introduzir um novo tipo de coluna (`JSON`) incompatível com MariaDB 10.1.

## 4. Hash do fechamento (`closure_hash`)

Objetivo: detectar alteração indevida do conteúdo fechado, seguindo exatamente o padrão já usado em `reconciliation_exceptions.signature_hash` / `reconciliation_candidates.signature_hash` (SHA-256, hex, `char(64)`, acompanhado de uma string de versão).

### Algoritmo

1. Montar uma estrutura associativa com os campos abaixo (nunca campos livres como nome de contraparte — só estrutura e valores financeiros/identificadores);
2. Normalizar cada valor:
   - datas em `Y-m-d`;
   - valores monetários como string decimal fixa de 2 casas (nunca `float`), no mesmo formato que `Money::fromCents()` produz;
   - status como o valor do enum (string maiúscula);
   - IDs como inteiros;
3. Ordenar toda lista por chave primária ascendente (`match_id`, `exception_id`, `metric_key`) — elimina ambiguidade de ordem de inserção;
4. Serializar com `json_encode` usando `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` e chaves de objeto em ordem alfabética (`ksort` recursivo antes de codificar);
5. Calcular `hash('sha256', $json)`.

### Campos incluídos no hash

```text
schema_version        (ex.: "closure-snapshot-v1")
engine_version         (versão do motor de matching vigente, ex.: "rules-v1")
account_id
reconciliation_session_id
period_start
period_end
matches[]              → { match_id, status, total_amount }  ordenado por match_id
exceptions[]            → { exception_id, status, type }       ordenado por exception_id
metrics[]                → { metric_key, metric_value }         ordenado por metric_key
```

### Campos deliberadamente fora do hash

`closed_by`, `closed_at`, `correlation_id` — são metadados do **ato** de fechar, não do **conteúdo** fechado. Eles já são protegidos por `audit_events` e pelas colunas próprias de `reconciliation_closures`. Misturá-los ao hash tornaria impossível verificar "o conteúdo financeiro é o mesmo" independentemente de quem/quando apertou o botão.

### Versionamento

Qualquer mudança na lista de campos, na normalização ou na ordenação exige um novo `schema_version` (ex.: `closure-snapshot-v2`), nunca uma reinterpretação silenciosa do `v1`. O mesmo princípio já aplicado a `engine_version` em ADR-011.

### Verificação

Um comando/rotina de auditoria (fora do escopo de implementação imediata da Fase 6, mas previsto) pode recalcular o hash a partir do `snapshot_payload` armazenado e comparar com `closure_hash`. Divergência é evidência de alteração indevida — não deve nunca ocorrer em operação normal, pois `reconciliation_closures` nunca sofre `UPDATE` após criada (ver §6).

## 5. Onde a Fase 6 se encaixa no domínio existente

```text
app/Domain/Reconciliation/
  Enums/
    ReconciliationSessionStatus.php   (estendido: + Closed, + Reopened)
  Closure/                             (novo)
    Enums/
      ReconciliationClosureStatus.php  (CLOSED, REOPENED)
    Exceptions/
      ReconciliationClosureRuleViolation.php (extends ReconciliationRuleViolation ou reaproveita a mesma classe)
    ReconciliationClosureReadiness.php  (value object: ready, blockers[], warnings[])

app/Application/Reconciliation/
  ReconciliationClosingFeature.php          (novo — mesmo padrão de ReconciliationFeature)
  ReconciliationClosureValidator.php        (novo)
  ReconciliationClosureSnapshotBuilder.php  (novo)
  ReconciliationClosureHashService.php      (novo)
  ReconciliationClosureService.php          (novo — orquestra close())
  ReconciliationReopeningService.php        (novo — orquestra reopen())

app/Models/
  ReconciliationClosure.php
  ReconciliationClosureMatch.php
  ReconciliationClosureException.php
  ReconciliationClosureMetric.php
  ReconciliationReopening.php

app/Http/Controllers/
  ReconciliationClosureController.php       (novo)

app/Http/Middleware/
  EnsureReconciliationClosingEnabled.php    (novo — mesmo padrão de EnsureReconciliationV2Enabled)
```

Nenhum arquivo acima existe hoje. A lista é a especificação de onde cada peça deve nascer, mantendo a mesma separação Domain/Application/Infrastructure já usada pelas Fases 1–5.

## 6. Imutabilidade de `reconciliation_closures`

Uma vez criada, uma linha de `reconciliation_closures` **nunca** recebe `UPDATE` nos campos de conteúdo (`snapshot_payload`, `closure_hash`, `period_start`, `period_end`, `closed_by`, `closed_at`). As únicas escritas subsequentes permitidas são as que já existem no padrão void/justify da base: marcar `status = REOPENED` e preencher `reopened_by`/`reopened_at` — nunca apagar ou reescrever o conteúdo original. Uma reabertura seguida de novo fechamento cria uma **nova linha** (`previous_closure_id` aponta para a anterior), nunca reaproveita a antiga. Isso espelha exatamente o que `void` já faz em `reconciliation_matches` (ADR-009) e o que `JUSTIFIED`/`RESOLVED` já fazem em `reconciliation_exceptions` (ADR-012).

## 7. Relação com a sessão existente

`reconciliation_sessions` já é a unidade "conta + período" (`unique(account_id, period_start, period_end)`). A Fase 6 **não cria uma nova unidade de agregação concorrente** — o fechamento é um evento que acontece *sobre* uma sessão existente. Uma sessão pode ter múltiplos fechamentos ao longo do tempo (fechar → reabrir → fechar de novo), formando uma cadeia auditável (§15 no `FASE_6_MODELO_DADOS.md`).

## 8. Fora de escopo desta fase (ver também §31 do pedido original)

Não fazem parte da Fase 6:

- desativação do legado (`avt_conciliacoes`, `/conciliacoes`);
- migração histórica completa de dados legados para fechamentos;
- auto-match ou baixa automática;
- Open Finance, CNAB novo, IA financeira;
- substituição de Contas a Pagar/Receber.

## 9. Referências

- `ADR-009-persistent-reconciliation-model.md`
- `ADR-011-reconciliation-matching-engine.md`
- `ADR-012-reconciliation-exception-queue.md`
- `FASE_6_MODELO_DADOS.md`
- `FASE_6_STATE_MACHINE.md`
