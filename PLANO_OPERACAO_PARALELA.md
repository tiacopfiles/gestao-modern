# Plano de operação paralela

## Princípio

```text
sistema atual (legado: /conciliacoes, Contas a Pagar/Receber sobre avt_*)
+
gestao-modern (núcleo novo: API v1, /reconciliacao-v2)
```

devem coexistir por tempo indeterminado. O novo sistema precisa **ganhar confiança operacional** antes de qualquer retirada gradual do legado — e mesmo essa retirada é uma decisão futura separada, fora do escopo desta finalização (ver item "Não desativar legado" abaixo).

## Por que a coexistência é estruturalmente segura

- O núcleo moderno (Fases 1–6) nunca escreve nas tabelas `avt_lancamentos`, `avt_recebimentos`, `avt_movimentos`, `avt_conciliacoes` — verificado por teste automatizado (`test_migrations_never_touch_protected_legacy_tables` e equivalentes em cada fase) e por grep estático das migrations.
- `/conciliacoes` (`ReconciliationController`) e `/contas-a-pagar`/`/contas-a-receber` (`FinancialController`, sobre `Lancamento`/`Recebimento`) permanecem exatamente como estavam antes da Fase 1 — nenhuma linha desses controllers foi alterada por este projeto.
- As duas bases de dados modernas e legadas compartilham apenas `contas`/`Conta` (cadastro de contas bancárias) como referência comum — por leitura, sem FK, sem escrita cruzada.
- Todas as funcionalidades novas (v2, matching, closing) ficam atrás de feature flags `false` por padrão — o legado é o comportamento padrão de fábrica em qualquer deploy novo.

## Etapas de operação paralela

```text
Etapa 0 — hoje
  legado 100% do volume real; gestao-modern só em ambiente local/homologação

Etapa 1 — núcleo/API ativos, sem UI nova visível
  API v1 pode ingerir títulos/transações reais em paralelo, sem nenhum usuário
  humano interagir com /reconciliacao-v2 ainda (flags V2 continuam false para uso
  humano; a API por si só não depende dessas flags)

Etapa 2 — V2 manual para grupo piloto
  poucos usuários nomeados testam conciliação manual nova, legado continua sendo
  a fonte de verdade operacional para todos os outros

Etapa 3 — matching assistido para o mesmo grupo piloto
  sugestões aparecem, mas toda confirmação continua humana

Etapa 4 — closing para o grupo piloto, após pendências de negócio resolvidas
  fechamento formal começa a ser testado com casos reais, mas o fechamento legado
  (/conciliacoes) continua disponível e sendo usado pelo restante da operação

Etapa 5 — ampliação gradual
  mais contas/usuários migram para o fluxo novo, sempre reversível via flag
```

Cada etapa é reversível instantaneamente para a etapa anterior via feature flag (`ROLLBACK_PLAN_FINAL.md` nível 1) — nenhuma etapa exige "desfazer" trabalho já feito no legado, porque o legado nunca para de funcionar.

## Critérios de sucesso da operação paralela

Critérios qualitativos, propositalmente sem SLA numérico inventado (números exigem validação do negócio):

- **Sem perda de título:** todo título ingerido pela API aparece corretamente em `financial_titles` e é rastreável até a origem (`source_system`/`external_id`).
- **Sem duplicação:** idempotência por `(source_system, external_id)` e deduplicação bancária por identidade forte funcionam sob uso real (não só nos testes sintéticos).
- **Sem divergência inexplicável:** toda divergência gerada pelo matching tem evidência determinística visível (`reconciliation_exceptions.evidence`) — o operador consegue entender *por que* aquilo é uma divergência.
- **Matching útil:** a proporção de sugestões aceitas vs. rejeitadas indica que o motor está ajudando (não gerando ruído) — medir depois de volume real, não assumir agora.
- **Fila gerenciável:** o número de divergências/candidatos pendentes não cresce sem controle; a operação consegue revisar a fila no ritmo em que ela é gerada.
- **Fechamento reproduzível:** um fechamento antigo, reaberto e comparado, mostra exatamente o que mudou — testado tecnicamente (`test_hash_is_deterministic...`), precisa ser validado também pela percepção do usuário financeiro.
- **Usuário consegue explicar os números:** a pessoa que fechou o período consegue responder "por que esse valor" olhando a tela de fechamento, sem precisar abrir o banco de dados.
- **Tempo operacional aceitável:** o fluxo novo não deve ser sistematicamently mais lento que o legado para tarefas equivalentes — critério qualitativo, a validar com o time operacional.

## O que NÃO fazer durante a operação paralela

- Não desativar `/conciliacoes` (mesmo depois de o novo sistema estar em uso — a desativação é uma decisão formal futura, separada, com escopo próprio).
- Não migrar dados históricos do legado para o núcleo novo automaticamente (ver `PLANO_MIGRACAO_E_COEXISTENCIA.md`).
- Não ampliar o público de uma etapa antes de revisar os critérios de sucesso da etapa anterior com o time operacional.
- Não habilitar `RECONCILIATION_CLOSING_ENABLED` para todos os usuários antes de as pendências de negócio da Fase 6 estarem resolvidas (ver `PENDENCIAS_NEGOCIO_FINAIS.md`).
