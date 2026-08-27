# ADR-008 — Ingestão OFX conservadora e segura

- Status: aceito
- Data: 2026-08-13

## Contexto

OFX existe em variantes SGML 1.x e XML, com diferenças entre bancos. Usar um parser XML permissivo pode habilitar entidades externas, expansão de entidades ou acesso à rede. Declarar suporte universal sem arquivos reais homologados seria incorreto.

## Decisão

Foi criado `BankStatementImporter`, implementado por `OfxBankStatementImporter`. O parser é propositalmente conservador e não instancia DOM/XML nem resolve recursos externos. Rejeita `DOCTYPE`, `ENTITY`, `SYSTEM`, `PUBLIC`, bytes NUL, conteúdo sem `<OFX>`, extrato sem `BANKTRANLIST` e arquivo sem blocos fechados `STMTTRN`.

São aceitos arquivos SGML/XML cuja parte transacional possua blocos `STMTTRN` fechados. O parser usa:

- `FITID` como identidade forte obrigatória;
- `TRNAMT` para direção pelo sinal e valor absoluto em DECIMAL;
- `DTPOSTED` para data e horário quando o offset está disponível;
- `NAME` e `MEMO` para descrição original;
- `CHECKNUM`, `REFNUM` e `ENDTOENDID` como referências opcionais;
- `CURDEF` para moeda, com BRL somente quando ausente;
- `BANKID`, `ACCTID`, `ACCTTYPE`, período e saldo contábil somente como metadados do lote.

O `account_id` operacional vem obrigatoriamente da requisição e precisa existir; metadados de conta do OFX não criam nem selecionam contas automaticamente. Arquivos são lidos do upload temporário, com nome normalizado, extensão `.ofx`, conteúdo verificado e limite configurável. Apenas hashes e metadados necessários são retidos.

## Limitações

- não há promessa de compatibilidade com todos os bancos ou dialetos OFX;
- agregados sem `STMTTRN` fechado, formatos proprietários, CNAB, CSV e Open Finance não são suportados;
- FITID ausente é uma rejeição rastreável, sem deduplicação por valor/data;
- saldo do extrato não é transformado em movimento artificial;
- novos fixtures reais anonimizados devem ser homologados banco a banco antes de ampliar o parser.
