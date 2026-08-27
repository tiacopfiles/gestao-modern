# ADR-003 — Coexistência com o legado

- Status: aceito
- Data: 2026-08-13

## Contexto

`avt_lancamentos`, `avt_recebimentos`, `avt_movimentos` e `avt_conciliacoes` estão em produção. Seus tipos, IDs textuais e ausência de constraints tornam uma migração big bang arriscada.

## Decisão

O núcleo novo é estritamente aditivo. As telas atuais continuam usando os models legados. `financial_titles` guarda `legacy_type` e `legacy_id` apenas como ponte rastreável e impõe unicidade ao par; esses campos não são a identidade do novo domínio.

As migrations padrão do scaffold Laravel para users, cache e jobs foram removidas do conjunto migrável porque nunca foram executadas no servidor, não descrevem o schema real e tentariam recriar `avt_users`. Nenhuma linha foi marcada manualmente em `avt_migrations`.

O baseline não tenta reconstruir uma instalação vazia do legado. Ele assume que as tabelas `avt_*` operacionais já existem e passa a controlar apenas migrations modernas compatíveis. Antes do deploy, `migrate --pretend` e backup integral são obrigatórios; migrations pendentes devem ser comparadas com a lista aprovada no runbook.

A sincronização histórica não é automática nesta fase. Um processo posterior, executado em lote controlado, poderá ler o legado e chamar o mesmo serviço idempotente usando `LEGACY_PAYABLE` e `LEGACY_RECEIVABLE`.

## Consequências

- Nenhuma tabela ou coluna legada é alterada.
- Deploys novos podem usar `php artisan migrate` sem tentar recriar estruturas legadas.
- Durante a transição existirão dois modelos em paralelo; relatórios só devem mudar de fonte após reconciliação e validação explícitas.
- O rollback das tabelas novas foi validado em banco isolado, mas não deve ser usado depois que o núcleo receber dados sem backup e plano explícito de recuperação.
