# ADR-017 — Conciliação por conta bancária: regra definitiva, limitação atual e solução obrigatória

- Status: **aceito** — a regra de negócio abaixo é definitiva e substitui o comportamento histórico
- **Atualização 26/08/2026:** a regra segue valendo inteira, mas a **premissa** de que a conta bancária seria indeduzível caiu — cada empresa opera por uma única conta. Ler junto com o **ADR-018**, que destrava a seção C sem reabrir a convenção da seção B.
- Data: 24/08/2026
- Relação: complementa ADR-009 (modelo persistente de conciliação) e ADR-010 (conciliação sem efeito colateral financeiro). **Não** substitui ADR-016, que trata de políticas de fechamento da Fase 6

## Contexto

A conciliação (Movimento do Período) foi construída sobre uma pergunta que as
origens não sabem responder: **por qual banco o dinheiro passou?**

`contas` e `contasareceber` foram conferidas coluna a coluna no
`information_schema` — 20 e 21 colunas. A única coluna de conta é `conta`, um
varchar com o nome da **empresa** (`Acop Files`, `Global Box`, `Duemagem`,
`Marco`, `Equus`…; 31 valores distintos em pagar, 26 em receber). Não existe
banco, e ele não é dedutível de nenhum outro campo.

Para destravar a entrega, a implementação atual adotou a convenção da **conta
bancária padrão da empresa** (`bank_accounts.is_default`): toda liquidação vinda
das origens é atribuída a ela.

O custo dessa convenção foi medido em 24/08/2026, sobre o exercício de 2026:

| Medida | Valor |
|---|---|
| Linhas que o sistema tem e nenhuma das 3 planilhas do Itaú tem | 311 |
| Destas, com id de lançamento de origem (não são invenção do sistema) | 309 |
| Entradas | R$ 413.165,46 |
| Saídas | R$ 2.218.444,83 |
| **Efeito líquido no saldo conciliado** | **−R$ 1.805.279,37** |

São pagamentos que a origem afirma que aconteceram, feitos por outro banco, e
que a conciliação do Itaú recebe indevidamente porque a atribuição é por
convenção e não por fato.

Congelar essa convenção como comportamento final oficializaria o defeito. Este
ADR existe para separar as três coisas que estavam misturadas.

## Decisão

### A. Regra de negócio definitiva

> A conciliação é criada para **um banco, uma conta e uma data**. Enquanto
> estiver **ABERTA**, apresenta automaticamente todos os Contas a Pagar e Contas
> a Receber **CONFIRMADOS** cuja **data de realização** corresponda à data da
> conciliação e cuja **conta bancária** corresponda à conta selecionada. Novos
> títulos confirmados após a criação são incorporados automaticamente enquanto a
> conciliação permanecer aberta. O operador também pode incluir entradas e saídas
> manuais vinculadas à mesma conta e data, sempre com descrição e auditoria. O
> saldo é calculado exclusivamente a partir do saldo inicial, recebimentos
> confirmados, pagamentos confirmados e movimentações manuais. Ao **FECHAR**, o
> resultado é congelado; alterações posteriores exigem reabertura formal e
> auditada.

Decorrências que fazem parte da regra e não são negociáveis:

1. **Vencimento não decide entrada na conciliação.** Vencimento responde "quando
   deveria acontecer"; a conciliação responde "quando efetivamente aconteceu". O
   recorte é `settlement_date`. (Vencimento continua sendo usado para escrever o
   histórico — `"V 30/04 - Fornecedor X"` — e para o bloco de pendências.)
2. **Confirmação é obrigatória.** Só entra o que tem liquidação
   `status = CONFIRMED`. Título em aberto não é movimento.
3. **Fórmula única e exclusiva:**
   ```text
   SALDO FINAL = SALDO INICIAL
               + RECEBIMENTOS CONFIRMADOS + ENTRADAS MANUAIS
               - PAGAMENTOS CONFIRMADOS   - SAÍDAS MANUAIS
   ```
   Nenhuma tela, relatório, exportação ou API pode recalcular isso por outro
   caminho. Existe um único serviço de cálculo e todos consomem ele.
4. **Granularidade diária.** A conciliação é de um dia. Relatórios mensais são
   composição de dias, nunca um período conciliado direto.
5. **Movimento manual é entidade própria.** Não cria nem altera Contas a Pagar
   ou Contas a Receber. É ajuste/movimento da conciliação, com conta, data,
   descrição, operação e valor.
6. **Um movimento pertence uma única vez à conciliação.** Reenvio de API, nova
   sincronização, atualização de página ou reexecução da tarefa agendada não
   podem duplicá-lo. Protegido no código **e** no banco.
7. **Informação financeira não se apaga.** Retirar uma linha da conciliação é
   *ignorar com justificativa*, com motivo obrigatório, estado visível
   (`Incluído` / `Ignorado`), auditoria e reversibilidade.

### B. Limitação atual da implementação — **temporária, não é a regra**

Enquanto a solução da seção C não existir, a implementação **não consegue**
cumprir a regra A e opera assim:

| Aspecto | Comportamento atual |
|---|---|
| Filtro real das liquidações | `financial_titles.account_id` = **empresa**, não conta bancária |
| Atribuição ao banco | Convenção `bank_accounts.is_default` da empresa |
| Conciliação de banco **não** padrão | Recebe **zero** liquidações da origem; só movimento manual |
| Código responsável | `PeriodStatementService::bancoEhPadraoDaEmpresa()` |
| Erro conhecido resultante | −R$ 1.805.279,37 em 2026 (tabela do Contexto) |

Esta limitação está registrada **como dívida técnica com valor medido**, e não
como política. Ela não deve ser citada como justificativa para números que não
fecham: é a causa deles.

O paliativo operacional aceito no interim é *ignorar com justificativa* a linha
que não passou pela conta — item 3 do escopo fechado. Paliativo, não solução:
depende de a operadora saber, uma a uma, por onde cada pagamento saiu.

### C. Solução obrigatória

**A liquidação precisa saber por qual conta bancária ocorreu.**

```text
Título
  └── Liquidação confirmada
        ├── settlement_date      (data de realização)
        ├── amount               (valor realizado)
        ├── bank_account_id      ← OBRIGATÓRIO
        └── status = CONFIRMED
```

Com isso, o recorte da conciliação deixa de ser

```sql
-- hoje: convenção
account_id = empresa AND banco_escolhido = conta_padrao_da_empresa
```

e passa a ser exatamente

```sql
-- alvo: fato
status = 'CONFIRMED'
  AND settlement_date = :data_da_conciliacao
  AND bank_account_id  = :conta_selecionada
```

Assim, para ACOP Files em 24/08/2026 com Itaú R$ 100.000, Sicredi R$ 40.000 e
Sicoob R$ 25.000, **cada conciliação recebe somente os seus** — sem depender de
conta padrão e sem exceção a explicar.

#### Os três caminhos que criam liquidação

`SettlementService::settle()` tem exatamente três chamadores, e cada um resolve
o `bank_account_id` de um jeito:

| Caminho | Arquivo | Como obtém a conta bancária |
|---|---|---|
| Tela "marcar como pago/recebido" | `TitleController:201` | **Operador escolhe** — campo novo, obrigatório |
| API pública | `Api/V1/FinancialTitleController:168` | Parâmetro novo, obrigatório |
| Sincronização das origens | `OriginSyncService:275` | **Não tem como saber** — ver política abaixo |

**Política para o sync**, que é o caso difícil e o que gera volume: a liquidação
nasce com `bank_account_id` **nulo** e a conciliação a trata como *não
atribuída* — ela **não entra em conciliação nenhuma** e aparece numa fila de
"aguardando definição de conta", no mesmo padrão do aviso que já existe para
título sem `account_id`. Atribuir por convenção é o que este ADR está encerrando.

Duas fontes futuras podem resolver a fila automaticamente, e o sistema já tem as
tabelas para as duas (`bank_accounts`, `bank_transactions`,
`reconciliation_matches`):

- **OFX do Itaú** — casar a liquidação com o fato bancário real dá a conta com
  prova, e é o caminho para o qual o sistema foi desenhado desde a Fase 3;
- **campo de banco na origem** — se a equipe financeira passar a registrar por
  onde pagou, o sync lê e o problema acaba na raiz.

Enquanto nenhuma existir, a fila é resolvida à mão pela operadora — e isso é
honesto: ela é a única que sabe.

## Consequências

**O que muda para o usuário:** durante a transição, liquidações vindas do sync
deixam de aparecer automaticamente e passam pela fila de atribuição. É mais
trabalho manual no começo, em troca de um saldo que fecha sem exceção. A
alternativa — continuar atribuindo por convenção — produz um relatório que
parece conferido e não está.

**Migração dos dados existentes:** as liquidações já gravadas não têm
`bank_account_id`. Elas **não** devem ser preenchidas em massa com a conta
padrão, pelo mesmo motivo que a convenção está sendo encerrada. Ficam nulas e
entram na fila. Conciliações já **FECHADAS** não são recalculadas — são retrato
do que se sabia no momento, e reescrevê-las violaria a própria regra A.

**Reversibilidade:** a coluna nova é aditiva e anulável no banco; a
obrigatoriedade vive na validação dos três caminhos de entrada. Voltar atrás é
relaxar a validação, sem migration destrutiva.

**Restrição técnica de produção:** MariaDB 10.1.10. Migrations usam
`SchemaCompat::hasColumn` e evitam `change()` — introspecção de coluna quebra no
10.1 (`generation_expression` só existe a partir do 10.2). Nada de nome de tabela
em SQL cru: produção usa prefixo `avt_`, que o query builder aplica e o raw não.

## Plano de execução

A ordem importa: não dá para certificar valores enquanto a alimentação da base
falha, nem implementar tela sobre um recorte que vai mudar.

1. **Este ADR.**
2. **Destravar o sync** — o título `90317` faz o ciclo de Contas a Pagar
   terminar em `[ERROR]` a cada 5 minutos em produção
   (`Título liquidado ou cancelado não pode ter a emissão alterado por reenvio`),
   com 5 rejeitados fixos ainda não investigados.
3. **`bank_account_id` na liquidação** — coluna, obrigatoriedade nos três
   caminhos, fila de não atribuídas, e o recorte da conciliação passando a usar
   a conta bancária.
4. **Escopo fechado da conciliação** (abaixo).
5. **Testes ponta a ponta com números conhecidos**, e só então deploy no 220.

## Escopo fechado da conciliação

Depois do item 3, estes nove itens encerram o módulo. Nada além disto entra sem
novo ADR.

| # | Item | Regra |
|---|---|---|
| 1 | Reabrir conciliação, com motivo obrigatório e auditoria | A.7 |
| 2 | Aviso de movimento novo em data já fechada, com botão Reabrir | A.7 |
| 3 | Ignorar/Justificar: motivo obrigatório, bloco de ignoradas, devolver | A.7 |
| 4 | Movimento manual criado dentro da conciliação herda empresa, **`bank_account_id`**, data — e guarda referência à conciliação de origem | A.5 |
| 5 | Atualização dinâmica ao abrir a conciliação aberta | A (dinâmica) |
| 6 | Coluna ORIGEM: `CONTAS A RECEBER` / `CONTAS A PAGAR` / `MANUAL` | tela |
| 7 | UNIQUE em `period_statement_lines` por `(period_statement_id, title_settlement_id)` e `(period_statement_id, manual_movement_id)` | A.6 |
| 8 | Conciliação diária: campo "Data da conciliação" | A.4 |
| 9 | Relatório mensal como composição de dias | A.4 |

## Critério de pronto

Para uma conta e um dia quaisquer:

```text
saldo inicial + tudo que entrou - tudo que saiu = saldo final
```

sem nenhuma exceção causada pela arquitetura. Enquanto a explicação de uma
diferença for "a origem não sabe o banco", este ADR não está cumprido.
