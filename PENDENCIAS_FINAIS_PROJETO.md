# Matriz final de pendências do projeto

Tipos: `TÉCNICO`, `NEGÓCIO`, `INFRAESTRUTURA`, `SEGURANÇA`, `PRODUÇÃO`, `OPERACIONAL`.

| Item | Tipo | Status | Bloqueia desenvolvimento? | Bloqueia produção? | Próxima ação |
|---|---|---|---|---|---|
| Homologação MariaDB 10.1 (schema + concorrência real, Fases 1–6) | INFRAESTRUTURA | **RESOLVIDO — PASS em 17/08/2026** (MariaDB 10.1.48 portátil; Docker segue inoperante) | Não | Não | Nenhuma. Ver `HOMOLOGACAO_MARIADB_FINAL.md` |
| 14 perguntas de negócio da Fase 6 (`PENDENCIAS_NEGOCIO_FINAIS.md`) | NEGÓCIO | Sem resposta formal; defaults seguros em uso | Não | **Sim** (para as políticas mais sensíveis: four-eyes, prazo, saldo) | Levar tabela ao financeiro; registrar cada resposta em ADR |
| Exportação de relatório de fechamento (`reconciliation:export`) | NEGÓCIO | Gate existe; endpoint não implementado | Não | Sim, se exportação for exigida antes de produção | Definir formato/conteúdo mínimo com auditoria externa |
| RBAC por allowlist (`Gate::define` + IDs em `config`/`env`), sem tabela de papéis | TÉCNICO | Dívida técnica documentada, funcional para o volume atual | Não | Não (funciona), mas recomendável revisar se o número de usuários crescer | Avaliar migração para tabela de papéis em fase futura, fora deste projeto |
| IDOR: `reconciliation:manage/close/reopen` sem escopo por conta | SEGURANÇA | Conhecido, não piorado nesta finalização | Não | Avaliar caso a caso (risco baixo com poucos usuários confiáveis) | Decisão de negócio: escopo por conta é necessário? |
| Segregação ator-fecha≠ator-reabre | NEGÓCIO | Não implementada (pergunta 11) | Não | Depende da política de controles internos do financeiro | Resposta formal do financeiro |
| `LegacyModel` com `$guarded = []` | TÉCNICO | Mitigado por `validate()` explícito em todos os controllers legados revisados | Não | Não | Manter disciplina; considerar `$fillable` explícito em revisão futura do legado (fora de escopo) |
| SAST/scanner de segurança dedicado | SEGURANÇA | Não executado nesta finalização (auditoria manual pontual apenas) | Não | Recomendado antes de produção real | Rodar scanner (ex.: `composer audit` já cobre dependências; faltaria SAST de código próprio) |
| Backup/restore testado em ambiente real | PRODUÇÃO | Não aplicável ainda (sem ambiente de produção definido) | Não | **Sim** | Ver `PRE_PRODUCTION_READINESS_FINAL.md` |
| Observabilidade de produção (logs/métricas/alertas) | OPERACIONAL | Logs estruturados existem (correlation_id); métricas/alertas de produção não configurados | Não | **Sim** | Ver `PRE_PRODUCTION_READINESS_FINAL.md` §observabilidade |
| Coexistência com legado (`/conciliacoes`, Contas a Pagar/Receber) | OPERACIONAL | Preservada, sem alteração nesta finalização | Não | Não (é pré-requisito, já satisfeito) | Ver `PLANO_OPERACAO_PARALELA.md` |
| Migração histórica de dados do legado | NEGÓCIO/OPERACIONAL | Fora de escopo, não iniciada | Não | Não (não é pré-requisito de produção inicial) | Ver `PLANO_MIGRACAO_E_COEXISTENCIA.md` — decisão futura separada |
| `reconciliation_rate` e saldo inicial/final | NEGÓCIO | Não calculados (fórmula/autoridade de saldo indefinidas) | Não | Não (métricas informativas, não bloqueantes) | Depende de resposta às perguntas 9 e 13 |
| Testes de concorrência real (InnoDB) | TÉCNICO/INFRAESTRUTURA | **RESOLVIDO — 11 cenários PASS**, incluindo 4 novos da Fase 6 (`close`/`reopen`/período sobreposto) | Não | Não | Nenhuma. Ver `HOMOLOGACAO_MARIADB_FINAL.md` §concorrência |
| Bug de prefixo em SQL cru (`ReconciliationAllocationQuery`) | TÉCNICO | **CORRIGIDO em 17/08/2026** — quebrava confirmação/desfazimento de match em qualquer banco com prefixo, inclusive produção (`avt_`) | Não | Não | Nenhuma; suíte rápida agora roda com `DB_PREFIX=avt_` para impedir recorrência |

## Leitura rápida (atualizada em 17/08/2026)

- **Nada nesta lista bloqueia o desenvolvimento continuar** ou o sistema ser demonstrado localmente.
- **A trava técnica caiu.** A homologação MariaDB 10.1 e a concorrência real InnoDB — os dois itens que bloqueavam produção por motivo técnico — estão `PASS`. Nenhum blocker técnico conhecido permanece aberto.
- **O que ainda bloqueia produção não é mais técnico:**
  1. **Negócio** — as 14 perguntas da Fase 6, em especial four-eyes, prazo de fechamento, janela de reabertura, saldo de autoridade e se exportação é exigida. Enquanto não respondidas, o sistema opera com os defaults seguros de ADR-015.
  2. **Operação** — backup/restore testado e observabilidade (métricas/alertas) em ambiente real.
  3. **Autorização humana** — nenhum deploy, migration real ou acesso a produção foi executado, por decisão de escopo.
- Os demais itens são dívida técnica documentada, sem impedir as frentes acima.
