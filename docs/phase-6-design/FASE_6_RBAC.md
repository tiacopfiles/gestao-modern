# Fase 6 — RBAC e segregação de funções

- Status: **proposta, não implementada**

## 1. Como o RBAC funciona hoje (base real, não hipotética)

Não existe tabela de papéis/permissões. `app/Providers/AppServiceProvider.php` define gates simples por *allowlist* de IDs de usuário, vindos de `config/reconciliation.php` (por sua vez lidos de variáveis de ambiente):

```php
Gate::define('reconciliation:view', fn (User $user) =>
    in_array($user->id, array_merge(config('reconciliation.view_user_ids'), config('reconciliation.manage_user_ids')), true));

Gate::define('reconciliation:manage', fn (User $user) =>
    in_array($user->id, config('reconciliation.manage_user_ids'), true));
```

E nas rotas (`routes/web.php`): `->middleware('can:reconciliation:view')` / `->middleware('can:reconciliation:manage')`. Os únicos outros gates do sistema (`payments`, `commercial`) seguem o mesmo padrão, mas seu allowlist vive numa coluna do usuário legado (`pagamentos`, `comercial`), não em env.

A Fase 6 **estende exatamente esse padrão** — não introduz um sistema de papéis novo, que seria desproporcional ao restante da base e headline de uma decisão de arquitetura maior do que o escopo desta fase.

## 2. Novas permissões propostas

| Permissão | Config/env correspondente | Significado |
|---|---|---|
| `reconciliation:view` | já existe | ver sessões, matches, divergências, candidatos — **e**, com a Fase 6, também ver fechamentos e histórico de reaberturas (nenhuma mudança necessária: quem já vê a sessão deve ver seu fechamento) |
| `reconciliation:manage` | já existe | criar sessão, confirmar/desfazer match, aceitar/rejeitar candidato, justificar exceção — **não muda de significado** |
| `reconciliation:close` | novo — `RECONCILIATION_CLOSE_USER_IDS` | executar `close()` sobre uma sessão pronta |
| `reconciliation:reopen` | novo — `RECONCILIATION_REOPEN_USER_IDS` | executar `reopen()` sobre um fechamento |
| `reconciliation:export` | novo — `RECONCILIATION_EXPORT_USER_IDS` | gerar exportação/relatório de um fechamento (§18, evento `RECONCILIATION_CLOSURE_EXPORT_GENERATED`) |
| `reconciliation:admin` | novo — `RECONCILIATION_ADMIN_USER_IDS` | operações extraordinárias — hoje a única prevista é fechar com exceção `OPEN` sob a política Extraordinária (`FASE_6_STATE_MACHINE.md` §4), se essa política vier a ser habilitada |

### Gates propostos (`AppServiceProvider::boot()`, adição, não substituição do que já existe)

```php
Gate::define('reconciliation:close', fn (User $user) =>
    in_array((int) $user->getKey(), config('reconciliation.close_user_ids', []), true));

Gate::define('reconciliation:reopen', fn (User $user) =>
    in_array((int) $user->getKey(), config('reconciliation.reopen_user_ids', []), true));

Gate::define('reconciliation:export', fn (User $user) =>
    in_array((int) $user->getKey(), array_merge(
        config('reconciliation.export_user_ids', []),
        config('reconciliation.close_user_ids', []),
    ), true));

Gate::define('reconciliation:admin', fn (User $user) =>
    in_array((int) $user->getKey(), config('reconciliation.admin_user_ids', []), true));
```

`reconciliation:export` inclui por padrão quem já tem `close`, seguindo o mesmo raciocínio hoje aplicado a `view` incluir `manage` — quem pode produzir o fechamento deve poder exportar seu próprio resultado sem depender de terceiros.

### `config/reconciliation.php` (adição de chaves, mesmo padrão do arquivo atual)

```php
'close_user_ids' => $ids(env('RECONCILIATION_CLOSE_USER_IDS')),
'reopen_user_ids' => $ids(env('RECONCILIATION_REOPEN_USER_IDS')),
'export_user_ids' => $ids(env('RECONCILIATION_EXPORT_USER_IDS')),
'admin_user_ids' => $ids(env('RECONCILIATION_ADMIN_USER_IDS')),
```

Reaproveita a função `$ids()` já definida no topo do arquivo.

## 3. Segregação de funções

O pedido original define o requisito central: **"Usuário A concilia, Usuário B fecha"** e **"quem fechou não necessariamente pode reabrir"**. O modelo de gates acima já permite isso — são allowlists independentes — mas a *aplicação* dessa segregação também precisa existir onde importa: no serviço, não apenas na rota.

### Matriz de permissões (papéis ilustrativos, não pessoas reais)

| Permissão | Operador de conciliação | Fechador | Auditor/gestor | Administrador do sistema |
|---|:---:|:---:|:---:|:---:|
| `reconciliation:view` | sim | sim | sim | sim |
| `reconciliation:manage` | sim | não (opcional) | não | sim |
| `reconciliation:close` | não | sim | não | sim |
| `reconciliation:reopen` | não | não (ver nota) | sim | sim |
| `reconciliation:export` | não | sim | sim | sim |
| `reconciliation:admin` | não | não | não | sim |

**Nota sobre `reconciliation:reopen`:** o requisito "quem fechou não necessariamente pode reabrir" é satisfeito por design (allowlists distintas), mas o caso mais forte de segregação é **impedir que o mesmo ator feche e reabra o mesmo fechamento**, não apenas separar os grupos. Isso é uma regra de negócio, não uma regra de gate — ver §4.

### Regra de negócio a validar (não é fato técnico)

> O mesmo usuário que executou `close()` pode executar `reopen()` sobre o **mesmo** fechamento?

Três respostas possíveis, nenhuma decidida ainda:

1. **Não, nunca** — exige um segundo ator com `reconciliation:reopen`, sempre diferente de `closed_by`. Mais forte, mas pode travar operações em equipes pequenas.
2. **Sim, mas com aviso** — permite, mas a UI e o `audit_events` destacam que o mesmo ator fechou e reabriu.
3. **Depende do papel** — quem tem `reconciliation:admin` pode reabrir o que fechou; quem só tem `reconciliation:reopen` não pode reabrir o que fechou.

Se a resposta for (1) ou (3), `ReconciliationReopeningService::reopen()` precisa comparar `$actorId` com `$closure->closed_by` e rejeitar com um novo código técnico (`CLOSURE_REOPEN_SAME_ACTOR_FORBIDDEN`) antes de prosseguir. Isso é fácil de implementar quando decidido — está listado como pendência em `FASE_6_PERGUNTAS_NEGOCIO.md`, não implementado a priori para não inventar política financeira como fato (regra explícita do pedido original, §10).

## 4. Onde a segregação é aplicada (camadas)

Mesmo racional de `FASE_6_STATE_MACHINE.md` §6:

1. **Gate (obrigatório):** rota exige `can:reconciliation:close` / `can:reconciliation:reopen` — igual ao padrão já usado para `reconciliation:view`/`manage`.
2. **Serviço (obrigatório, se a política de "ator diferente" for aprovada):** `ReconciliationReopeningService` valida `closed_by !== $actorId` antes de qualquer lock, no mesmo lugar onde `ManualReconciliationService::assertActor()` já valida o ator.
3. **UI:** oculta o botão de reabrir para o próprio fechador quando a política estiver ativa — conveniência, não proteção real.

## 5. Compatibilidade com o legado

Nenhuma permissão nova depende ou interage com `avt_*`. O padrão `pagamentos`/`comercial` (coluna no usuário legado) não é estendido — todas as permissões novas seguem o padrão mais recente (`config` + `env`), já usado por `reconciliation:view`/`manage`, evitando adicionar mais colunas à tabela de usuários legada.
