# Integração Contas a Pagar / Contas a Receber → Gestão

Objetivo: **acabar com a digitação dupla**. A funcionária continua cadastrando no
sistema de origem; o Gestão recebe a informação e concentra o que entrou, saiu,
foi pago, recebido, conciliado, e qual o saldo após cada movimento.

Este documento é o mapa para conectar os dois sistemas. **Nada aqui foi aplicado
nos sistemas de origem** — eles não foram acessados nem alterados.

---

## 1. Estado da integração hoje

| Fluxo | Situação | Evidência |
|---|---|---|
| Contas a Pagar → Gestão | **NÃO INTEGRADO** | Nenhuma chamada à API do Gestão existe no sistema de origem. Os sistemas sequer estão acessíveis nesta máquina (ver §2). |
| Contas a Receber → Gestão | **NÃO INTEGRADO** | Idem. |
| Gestão → origem (volta) | **NÃO EXISTE, e é proposital** | Decisão pendente. Marcar como pago no Gestão nunca escreve na origem. |

Distinção que importa: **o Gestão tem os endpoints prontos**; o que falta é o
sistema de origem chamá-los. São coisas diferentes, e só a segunda está pendente.

## 2. Localização dos sistemas de origem

Na investigação desta sessão os dois sistemas **não foram encontrados nesta
máquina**:

```text
G:\xampp\htdocs\contas            → drive G: não montado
G:\xampp\htdocs\contasareceber    → drive G: não montado
C:\xampp\htdocs\                  → contém agrocolitti, gestao, gestao-redesign; NÃO contém contas/contasareceber
K:\ (\\192.168.0.222\rede)        → compartilhamento de arquivos, sem código
```

Portanto o levantamento de campos, telas e pontos de gancho **não pôde ser feito
sobre o código real**. O mapeamento da §4 usa como referência a estrutura das
tabelas legadas que o próprio Gestão já lê (`lancamentos` e `recebimentos`), que
é a mesma do sistema de origem.

**Para completar este documento:** montar o drive G: (ou informar onde os
projetos estão) e refazer a leitura — somente leitura.

## 3. Onde o Gestão recebe a informação

A API v1 já expõe tudo o que a integração precisa. **15 operações**, das quais
estas 6 interessam à integração:

| Operação | Método e rota | Escopo |
|---|---|---|
| Criar título a pagar | `POST /api/v1/payables` | `payables:write` |
| Atualizar título a pagar | `PUT /api/v1/payables/{external_id}` | `payables:write` |
| Cancelar título a pagar | `POST /api/v1/payables/{external_id}/cancel` | `payables:write` |
| **Informar pagamento** | `POST /api/v1/payables/{external_id}/settlements` | `payables:write` |
| Criar/atualizar/cancelar a receber | mesmas rotas em `/receivables` | `receivables:write` |
| **Informar recebimento** | `POST /api/v1/receivables/{external_id}/settlements` | `receivables:write` |

As rotas de liquidação (`/settlements`) **foram criadas nesta sessão** — eram a
peça que faltava. O `SettlementService` já existia desde a Fase 1, mas não estava
exposto em lugar nenhum: nem API, nem tela.

Todas as escritas exigem cabeçalho `Idempotency-Key` e aceitam
`X-Correlation-ID`. Reenviar a mesma requisição não duplica nada.

## 4. Mapeamento campo a campo

### Contas a Pagar (`lancamentos`) → `POST /api/v1/payables`

| Campo na origem | Campo no Gestão | Compatível? | Transformação |
|---|---|---|---|
| `id` | `external_id` | ✅ | converter para string |
| `numero_doc` | `document_number` | ✅ | direto |
| `fornecedor` | `party.id` + `party.name` | ✅ | id do fornecedor e nome; `party.type` = `SUPPLIER` |
| `data_emissao` | `issue_date` | ✅ | formatar `Y-m-d` |
| `data_vencimento` | `due_date` | ✅ | formatar `Y-m-d` |
| `valor` | `original_amount` | ✅ | string com 2 decimais: `"1500.00"` |
| `acrescimo` | `addition_amount` | ✅ | idem |
| `desconto` | `discount_amount` | ✅ | idem |
| `valor_total` | — | ⚠️ | **não enviar**: o Gestão calcula e recusa o campo |
| `conta` | `account_id` | ✅ | id da conta bancária |
| `categoria` | `category_id` | ✅ | direto |
| `centrocusto` | `cost_center_id` | ✅ | direto |
| `numero_pc` | `installment_count` | ✅ | número de parcelas (1 se vazio) |
| `obs` | `notes` | ✅ | direto |
| `situacao` | — | ⚠️ | **não enviar**: status é derivado das liquidações |
| `data_pagamento` | — | ⚠️ | **não enviar aqui**: vira uma chamada a `/settlements` |

### Contas a Receber (`recebimentos`) → `POST /api/v1/receivables`

Idêntico, com `cliente` no lugar de `fornecedor` e `party.type` = `CUSTOMER`.

### Pagamento/recebimento → `POST /.../{external_id}/settlements`

| Campo na origem | Campo no Gestão | Observação |
|---|---|---|
| `data_pagamento` | `settlement_date` | obrigatório, `Y-m-d` |
| valor baixado | `amount` | **opcional** — omitir liquida o saldo restante, que é o caso normal |
| parcela | `installment_number` | só para título parcelado |
| id da baixa na origem | `external_id` | recomendado: torna o reenvio idempotente |

**Duas conversões merecem atenção**, porque são onde integrações costumam
quebrar:

1. **Dinheiro é string, nunca float.** O contrato exige `"1500.00"` — ponto
   decimal, sempre 2 casas. Enviar `1500` ou `1500,00` é recusado.
2. **`valor_total` e `situacao` são calculados pelo Gestão** e explicitamente
   proibidos no payload. Isso é proposital: o total é derivado de
   original − desconto + acréscimo, e o status vem das liquidações. Deixar a
   origem ditá-los permitiria os dois sistemas discordarem.

## 5. Onde inserir o gancho no sistema de origem

**Não implementado** — os sistemas não estavam acessíveis. O ponto lógico:

```text
Contas a Pagar
├── ao salvar um lançamento novo ......... POST   /api/v1/payables
├── ao editar um lançamento .............. PUT    /api/v1/payables/{id}
├── ao marcar como pago (data_pagamento) . POST   /api/v1/payables/{id}/settlements
└── ao excluir/cancelar .................. POST   /api/v1/payables/{id}/cancel
```

Recomendação de desenho, na ordem de preferência:

1. **Evento na origem → chamada HTTP** (preferido). Menor acoplamento; o Gestão
   nunca lê o banco da origem.
2. **Fila/tabela de saída na origem**, consumida por um worker que chama a API.
   Mais robusto a indisponibilidade, mais peças.
3. **Leitura direta do banco pelo Gestão** — desaconselhado: acopla os dois
   schemas e contorna toda a validação e auditoria da API.

Enquanto o gancho não existe, o Gestão **não fica parado**: a ação rápida
"marcar como pago/recebido" (§6) cobre a operação manualmente.

## 6. Enquanto a integração não existe

Tela `/titulos`: lista títulos com situação e um botão de ação rápida —
**EM ABERTO → PAGO** (ou RECEBIDO) em um clique, pedindo só a data.

Isso registra a realização no Gestão e **não altera o sistema de origem** —
integração de volta é decisão pendente, e implementá-la a priori seria inventar
política.

## 7. Fronteira que não pode ser perdida

```text
Título aberto
    ↓  marcar como pago/recebido   ← "meu sistema considera isso realizado"
Título realizado
    ↓  importar extrato bancário
Fato bancário
    ↓  conciliar (match)           ← "achei a prova no banco"
Conciliado
    ↓  fechar período
Fechado
```

Realizar e conciliar são coisas **diferentes** (ADR-010). Marcar como pago nunca
cria conciliação — existe teste automatizado garantindo exatamente isso
(`TitleSettlementTest::test_settlement_does_not_create_any_reconciliation`).

## 8. Menor caminho para conectar de verdade

1. Montar o drive G: e localizar os dois projetos (leitura).
2. Emitir um token de integração por sistema, com escopo mínimo
   (`payables:write` para um, `receivables:write` para o outro).
3. Copiar os projetos para `contas-pagar-local-integracao` e
   `contas-receber-local-integracao` e implementar o gancho **na cópia**.
4. Testar contra o Gestão local com dados sintéticos.
5. Rodar em paralelo: a origem continua sendo a verdade; o Gestão espelha.
6. Só então avaliar integração de volta (Gestão → origem), que hoje **não
   existe por decisão**.
