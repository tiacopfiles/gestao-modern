# ADR-018 — Uma empresa, uma conta bancária: a conta da liquidação passa a ser dedutível

- Status: **aceito**
- Data: 26/08/2026
- Relação: complementa e **destrava** o ADR-017. Não revoga a decisão dele — revoga a *premissa* sobre a qual ele foi escrito.

## Contexto

O ADR-017 foi escrito sob uma incerteza declarada: as origens (`contas` e
`contasareceber`) não guardam banco, e portanto o sistema **não teria como
saber** por qual conta cada pagamento passou. Dessa incerteza saíram três
coisas:

1. a convenção da conta padrão (`bank_accounts.is_default`), medida em
   **−R$ 1.805.279,37** sobre 2026 e encerrada como política;
2. a previsão de uma fila de atribuição manual, item a item;
3. o OFX do Itaú como única solução definitiva.

Em **26/08/2026** a administração respondeu a pergunta que nunca tinha sido
feita diretamente: **cada empresa opera por uma única conta bancária.**

Isso muda o problema de natureza. A informação não estava perdida — estava
sendo tratada como ambígua quando não era. Se a empresa tem uma conta só, a
liquidação que chega sem banco passou por ela, porque não existe outra por onde
passar. Não é escolher entre várias; é ler um cadastro com um item só.

Duas outras informações vieram junto e não exigem código, mas mudam a
interpretação dos números:

- **A data lançada em `contas`/`contasareceber` é a data real do pagamento.** A
  operadora paga na sexta e, na segunda, lança com a data da sexta. Logo
  `datapgto` é data de fato, não data de digitação, e `settlement_date` já é o
  recorte certo da conciliação.
- **O que não passa pelas origens é lançado à mão na conciliação.** É o que
  `manual_movements` já faz, e é o caminho oficial — não uma exceção.

## Decisão

### A. A conta bancária da liquidação é deduzida quando há uma só

`SettlementService::settle()` passa a resolver `bank_account_id` nulo para a
única conta ativa da empresa do título (`BankAccount::contaUnicaDaEmpresa()`).
Vale para os três caminhos de criação, inclusive o sync — que é o que gera
volume.

**Com duas ou mais contas ativas, continua nulo.** É aqui que o ADR-017 segue
valendo integralmente: a premissa deixou de valer, a informação falta de
verdade, e o sistema não elege nenhuma. Chutar entre duas é a convenção que
custou R$ 1,8 milhão.

Título sem `account_id` (empresa não resolvida na origem) também continua nulo:
sem empresa não há cadastro de conta para consultar.

`bank_account_id` continua **fora** do `payload_hash`, então a dedução não
invalida a idempotência das liquidações já gravadas.

### B. O recorte da conciliação passa a usar a coluna

`PeriodStatementService::linhasDeLiquidacoes()` deixa de decidir por convenção
(`bancoEhPadraoDaEmpresa()`, que devolvia lista vazia para qualquer banco não
padrão) e passa a filtrar pelo fato gravado:

```sql
bank_account_id = :conta_selecionada
  OR (bank_account_id IS NULL AND :conta_selecionada é a única conta da empresa)
```

O segundo ramo (`bancoHerdaSemConta()`) é o que faz as **11 mil liquidações
históricas**, todas com banco nulo, continuarem aparecendo sem precisar de
backfill. Não se reescreve dado gravado para mudar uma regra de leitura.

Consequência secundária, e boa: uma conta que não é a padrão deixa de receber
lista vazia por decreto. Ela recebe o que aponta explicitamente para ela.

### C. A pendência vira número visível

`contarSemContaBancaria()` conta as liquidações do período que ficaram fora por
não ter banco definido, e as telas de criar e de detalhe mostram o aviso. Devolve
zero quando a empresa tem uma conta só — nesse caso não há pendência, e avisar
seria alarme falso.

**Avisa sem bloquear.** Travar o fechamento por causa disso pararia a operação
todo dia enquanto houvesse passivo histórico.

## O que este ADR NÃO resolve

As **311 linhas / −R$ 1.805.279,37** de 2026 **não são explicadas** por esta
mudança, e é importante não deixar isso confuso.

Aquela medição foi feita **com a convenção da conta padrão ligada** — ou seja,
com o sistema já atribuindo tudo da empresa a uma conta só, que é exatamente o
cenário que a administração descreveu. Se cada empresa tem uma conta, aqueles
309 pagamentos com id de origem passaram pelo Itaú e deveriam estar na planilha.

Então a causa mudou de lugar, não desapareceu. Restam duas hipóteses, e só a
equipe financeira decide entre elas:

- a planilha está incompleta (a operadora não digita tudo o que sai); ou
- a origem afirma "pago" para o que não saiu — e já há defeito conhecido desse
  tipo: 11 lançamentos `situacao='pago'` com `datapgto` nulo e 156 com ano
  errado, R$ 408 mil.

**Decisão de escopo (26/08/2026, do usuário):** o passivo histórico não será
perseguido. O critério de sucesso passa a ser **o que entra de agora em diante
fechar**. Este ADR é o que torna isso possível; a apuração do retroativo fica
disponível se e quando alguém pedir.

## Correção de defeito encontrada no caminho

`linhasDeLiquidacoes()` e `contarSemConta()` usavam
`whereBetween(settlement_date, [from, to])`. O cast `immutable_date` grava
`"2026-01-15 00:00:00"`; no SQLite essa string é **maior** que `"2026-01-15"`, e
o último dia do período some. Numa conciliação diária, em que `from` e `to` são
o mesmo dia, **some tudo**.

No MariaDB a coluna é `DATE` e o servidor trunca, então o defeito não aparecia
em produção — o que o torna pior, não melhor: o mesmo código dava resultado
financeiro diferente conforme o driver. Corrigido para
`>= from AND < to + 1 dia`, que é o idioma que `linhasDeMovimentosManuais()` já
usava, e que continua usando o índice.

## Consequências

- Liquidações do sync passam a entrar na conciliação **sozinhas**, sem fila
  manual, enquanto cada empresa tiver uma conta só.
- Cadastrar uma segunda conta bancária para uma empresa **muda o comportamento
  do sistema**: a partir daí o que vier sem banco fica de fora e é contado como
  pendência. Isso é deliberado e precisa ser dito a quem cadastra.
- Reversível sem migration: a dedução vive em um método, e o recorte em outro.
- Nenhum dado gravado é reescrito. Não há backfill.

## Testes

`ConciliacaoPorBancoTest` (27), com destaque para:

- `test_liquidacao_da_origem_recebe_a_conta_unica_da_empresa`
- `test_com_duas_contas_a_liquidacao_da_origem_fica_sem_banco`
- `test_a_liquidacao_sem_banco_e_contada_como_pendencia`
- `test_a_conciliacao_de_um_unico_dia_traz_o_movimento_daquele_dia`
