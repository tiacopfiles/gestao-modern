# ADR-006 — Modelo canônico de transação bancária

- Status: aceito
- Data: 2026-08-13

## Contexto

O núcleo financeiro já representa títulos, parcelas e liquidações, mas ainda não possui uma representação confiável do que o banco registrou. A tabela legada de movimentos mistura conceitos e não deve ser usada nem alterada nesta fase. Também não existe vínculo confiável entre conta bancária e as tabelas modernas que justifique uma FK para o cadastro legado `contas`.

## Decisão

`bank_transactions` representa um fato bancário importado e imutável. Ele permanece separado de `title_settlements`: importar crédito ou débito não liquida, não cancela e não associa título algum.

O valor é armazenado como `DECIMAL(15,2)` positivo e sua natureza em `direction` (`CREDIT` ou `DEBIT`). Isso evita sinal ambíguo e mantém consultas explícitas. Datas de transação e contabilização, descrição original, referência bancária, End-to-End ID, contraparte e saldo posterior são preservados quando disponíveis.

A identidade forte é `(account_id, source_system_id, external_id)`, protegida por constraint única. `external_id` é obrigatório na API canônica e corresponde a `FITID` no OFX. Valor e data, mesmo combinados, nunca identificam uma transação. `payload_hash` detecta a tentativa de reutilizar a mesma identidade com conteúdo diferente; `raw_hash` auxilia rastreabilidade sem expor conteúdo bruto.

`account_id` é obrigatório e sua existência é validada pela aplicação no modelo legado `Conta`. Não foi criada FK para `contas`: o cadastro é legado, prefixado e sua integridade não foi comprovada para uma restrição nova. As FKs novas apontam apenas para `source_systems` e `import_batches`.

Não há endpoints PUT ou DELETE. Correções e estornos bancários futuros devem ser novos fatos, não mutações silenciosas.

## Consequências

- títulos e fatos bancários podem evoluir independentemente até a Fase 4;
- a conta e a origem fazem parte da identidade e do isolamento;
- duas transações legítimas com mesmo valor e data são preservadas se tiverem IDs fortes diferentes;
- identidade ausente ou fraca é rejeitada e rastreada no item do lote;
- eventual exclusão/renumeração de conta legada precisa de governança antes de produção, pois não existe FK deliberadamente.
