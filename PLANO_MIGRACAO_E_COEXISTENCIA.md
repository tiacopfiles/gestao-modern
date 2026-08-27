# Plano de migração e coexistência

## Regra desta finalização

**Nenhum dado histórico real do legado foi migrado, e nenhuma migração histórica será implementada nesta execução.** Este documento apenas organiza o espaço de decisão para uma fase futura separada, com escopo próprio a ser aprovado antes de qualquer implementação.

## Três categorias de dados, tratadas de formas diferentes

### 1. Dados novos (a partir de agora)

Tudo que entrar pela API v1 ou pelo fluxo `/reconciliacao-v2` a partir do momento em que o núcleo moderno começar a operar em paralelo (ver `PLANO_OPERACAO_PARALELA.md`). Não exige migração — é criado diretamente no modelo novo (`financial_titles`, `bank_transactions`, `reconciliation_*`).

### 2. Histórico legado (dados já existentes em `avt_*`)

Continua exatamente onde está, acessível apenas por `/conciliacoes` e Contas a Pagar/Receber legados. **Não é tocado, não é copiado, não é referenciado por nenhuma tabela moderna.** Nenhuma FK do núcleo novo aponta para tabelas legadas (decisão já registrada em ADR-003 e reafirmada em todas as fases seguintes).

### 3. Somente consulta (cenário futuro possível, não implementado)

Uma eventual necessidade de "ver o histórico legado dentro da tela nova, sem migrar nada" — por exemplo, um relatório que combine dados novos e antigos apenas para leitura. **Não existe hoje.** Se vier a ser necessário, deve ser implementado como uma consulta de leitura pura (sem escrita, sem FK), nunca como pré-requisito para o núcleo novo funcionar.

## Migração futura (não iniciada, não aprovada)

Se, no futuro, o negócio decidir migrar histórico real do legado para o núcleo moderno, isso precisa de:

1. Escopo formal aprovado (quais tabelas, qual período, qual volume).
2. Mapeamento de campo a campo entre o schema legado (`avt_lancamentos`/`avt_recebimentos`/`avt_movimentos`/`avt_conciliacoes`) e o schema moderno (`financial_titles`/`title_installments`/`title_settlements`/`bank_transactions`/`reconciliation_*`), incluindo decisões de negócio sobre casos que não têm correspondência direta (ex.: como um "match" legado vira um `reconciliation_match` com auditoria completa e ator identificado, quando o legado pode não ter essa granularidade).
3. Ambiente de teste com uma cópia sintética/anonimizada dos dados legados antes de qualquer migração real — nunca migrar direto de produção para produção sem ensaio.
4. Estratégia de idempotência para a migração em si (rodar duas vezes não deve duplicar).
5. Um `source_system` dedicado para identificar dados migrados (ex.: `LEGACY_PAYABLE`/`LEGACY_RECEIVABLE`, que já existem como código semeado na migration de `source_systems` — reservados para esse uso futuro, ainda não usados por nenhum fluxo ativo).
6. Aprovação explícita de negócio, técnico e segurança — mesmo padrão de gate de `PRE_PRODUCTION_READINESS_FINAL.md`.

Nada disso está implementado. Este plano existe apenas para que uma decisão futura não precise começar do zero.

## Por que não migrar agora

- O núcleo moderno ainda não passou por homologação MariaDB nem por operação paralela real (ver `PLANO_OPERACAO_PARALELA.md`) — migrar histórico antes de o próprio núcleo estar validado multiplicaria o risco.
- Migração histórica é, por natureza, uma operação de alto volume e baixa reversibilidade — não deve ser feita sob pressão de prazo de finalização de projeto.
- O sistema já é funcionalmente completo e demonstrável sem migração histórica (títulos e transações novos entram normalmente pela API/UI nova).

## Coexistência sem migração é suficiente para começar

A operação paralela (`PLANO_OPERACAO_PARALELA.md`) não depende de migração histórica: o legado responde por tudo que já existia, o núcleo novo responde por tudo que é criado depois de ativado. Essa é a estratégia recomendada para a próxima fase real do projeto — migração histórica é opcional e posterior, não um bloqueio.
