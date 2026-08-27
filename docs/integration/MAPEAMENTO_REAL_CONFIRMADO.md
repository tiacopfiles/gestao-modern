# Mapeamento real confirmado — Contas a Pagar / Contas a Receber → Gestão

Levantado por leitura direta do código e dos bancos de produção em 17/08/2026.
**Nenhuma escrita foi feita nas origens.**

Classificação usada: **[CÓDIGO]** confirmado no código · **[BANCO]** confirmado
por consulta · **[INFERÊNCIA]** deduzido · **[NÃO CONFIRMADO]**.

---

## 1. Identificação dos sistemas

| | Contas a Pagar | Contas a Receber |
|---|---|---|
| Caminho | `\\192.168.0.220\xampp\htdocs\contas` | `\\192.168.0.220\xampp\htdocs\contasareceber` |
| Banco | `contas` **[CÓDIGO]** `contasdb.php` | `contasareceber` **[CÓDIGO]** `contasareceberdb.php` |
| Servidor | `192.168.0.220:3306`, MariaDB 10.1.10 **[BANCO]** | idem |
| Framework | PHPMaker 11 (PHP procedural gerado) **[CÓDIGO]** | idem |
| Tabela principal | `lancamentos` **[BANCO]** | `lancamentos` **[BANCO]** |
| Registros | 91.067 **[BANCO]** | 66.230 **[BANCO]** |
| Tela principal | `lancamentoslist.php` **[CÓDIGO]** | idem |

> O drive `G:` citado historicamente corresponde ao compartilhamento
> `\\192.168.0.220\xampp`. Ele não está mapeado nesta máquina; o acesso foi feito
> pelo caminho UNC.

### Por que `contas` é Pagar e `contasareceber` é Receber

Os dois projetos são clones do mesmo gerador: menus idênticos, mesmo
`EW_PROJECT_NAME = "contas"`, mesma tabela `lancamentos`, mesmos nomes de
arquivo. **O nome da pasta não é evidência.** A distinção veio dos dados de
referência **[BANCO]**:

| Evidência | `contas` | `contasareceber` |
|---|---|---|
| Tipos de documento | Guia, Fatura de Compra, Ordem de Serviço, Débito Automático, Conhec. Transporte | Serviço, Depósito em C/C, Contrato |
| Categorias | PIS s/Fat., COFINS s/Fat., CSLL, IRPJ, Material Limpeza, Manutenção Veículo | Aluguel Imóvel, IPTU 2009–2015, Condomínio, Empréstimo |
| Centros de custo | Imposto s/ faturamento, Imposto s/ folha, Mão de Obra (21 no total) | **Receita** e Investimento/Parque das Flores (2 no total) |
| Situações extras | — | **Negativados, processo, anuência, Extintos, Permuta** |

Tributos a recolher e despesas operacionais de um lado; aluguel, IPTU,
condomínio e cobrança judicial do outro. Conclusão: **`contas` = A PAGAR**,
**`contasareceber` = A RECEBER**.

---

## 2. Semântica real do status **[BANCO]**

O campo autoritativo é `situacao` — texto livre validado contra a tabela
`situacao`. **`datapgto` não decide o status**: existem 66 registros a pagar e
26 a receber com `situacao='pago'` e sem data, e 25 `canc` **com** data.

### Contas a Pagar (4 situações)
| situacao | registros | significado |
|---|---:|---|
| `pago` | 90.574 | realizado |
| `aberto` | 468 | em aberto |
| `canc` | 18 | cancelado |
| `aguard` | 6 | aguardando — **não** realizado |
| `NULL` | 1 | indefinido |

### Contas a Receber (9 situações + compostos)
| situacao | registros | significado |
|---|---:|---|
| `pago` | 59.176 | realizado |
| `aguard` | 3.604 | aguardando (valor e vencimento nulos) |
| `aberto` | 3.039 | em aberto |
| `Permuta` | 120 | quitado por permuta — **não** é dinheiro |
| `processo` | 107 | em cobrança judicial |
| `anuência` | 74 | — |
| `canc` | 44 | cancelado |
| `Negativados` | 35 | devedor negativado |
| `Extintos` | 25 | — |
| `aberto,Negativados` | 3 | **composto** |
| `aberto,processo` | 1 | **composto** |
| `processo,Negativados` | 1 | **composto** |
| `NULL` | 1 | indefinido |

**Regra adotada:** REALIZADO ⟺ `situacao = 'pago'`. Todas as demais mantêm o
título em aberto. `Permuta` merece decisão do financeiro — o título foi quitado,
mas não por dinheiro **[NÃO CONFIRMADO]**.

---

## 3. Mapeamento campo a campo

| Origem | Tipo real | Gestão | Regra | Confirmado |
|---|---|---|---|---|
| `id` | int(11) PK | `external_id` | string do id; 100% único | [BANCO] |
| `ndocumento` | varchar(255) | `document_number` | trunca em 120; **repete muito** (`SNF` aparece 1.112×) — nunca usar como identidade | [BANCO] |
| `nomefantasia` | varchar(255) | `party.name` | `SUPPLIER` em pagar, `CUSTOMER` em receber | [BANCO] |
| `dataemissao` | date | `issue_date` | ver §4 | [BANCO] |
| `vencimento` | date | `due_date` | direto | [BANCO] |
| `datapgto` | date | `settlement_date` | só quando `situacao='pago'` | [BANCO] |
| `valortotal` | double(20,2) / decimal(20,2) | `original_amount` | **ver §4** | [BANCO] |
| `valor`,`acrescimo`,`desconto` | — | `notes` | preservados como texto | [BANCO] |
| `conta` | varchar(255) **nome** | `account_id` | resolvido por NOME, ver §4 | [BANCO] |
| `categoria`,`centrocusto`,`tipo` | varchar(255) | `notes` | não há id estável | [BANCO] |
| `obs` | varchar(255) | `notes` | direto | [BANCO] |
| `parcela`,`nparcela` | varchar(255) | `notes` | **nunca** `installment_count`, ver §4 | [BANCO] |
| `situacao` | varchar(255) | — | deriva realização | [BANCO] |
| `cnpj` | varchar(255) | — | não enviado (dado pessoal) | — |
| `acesso` | int(2) | — | só em receber; propósito **[NÃO CONFIRMADO]** | — |

---

## 4. Quatro armadilhas que corromperiam os números

Cada uma foi confirmada nos dados e tratada no extrator.

### 4.1 Uma linha É uma parcela
`ndocumento = 00.003.447` aparece em **3 linhas**: parcelas 01, 02 e 03 de 03,
com valores 166,67 / 166,67 / 166,66 e vencimentos distintos.

→ `installment_count` é **sempre 1**. Usar `nparcela` multiplicaria o dinheiro
pelo número de parcelas.

### 4.2 `valortotal` não é derivável
944 registros têm `valortotal ≠ valor + acrescimo − desconto`. Exemplo real:
`valor=1275,55`, sem acréscimo nem desconto, mas `valortotal=1275,66`. São
ajustes manuais dos usuários.

→ `original_amount = valortotal`, com desconto e acréscimo zerados. É o único
jeito de o total do Gestão bater com o que o financeiro vê hoje. A decomposição
original vai para `notes`.

### 4.3 `double` perde casas decimais
Em `contas` as colunas monetárias são `double(20,2)` — ponto flutuante. Lidas
direto, `670000.00` chega como `670000`.

→ `CAST(CAST(campo AS DECIMAL(20,2)) AS CHAR)` no servidor, sempre.

### 4.4 IDs de conta colidem entre os sistemas
15 nomes existem nos dois bancos com ids diferentes: `Marco` é 1 em `contas` e
16 em `contasareceber`; `Acop Files` é 2 e 19.

→ A conta do Gestão é resolvida pelo **nome**, unificando os dois sistemas. Usar
o id da origem separaria em duas contas o que é uma só.

### Outros achados de qualidade (reportados, não corrigidos)
- `dataemissao` máxima em pagar: **3012-09-30** (erro de digitação).
- Emissão posterior ao vencimento em 90 registros → emissão ajustada para o
  vencimento e o caso é contabilizado, nunca silenciado.
- `datapgto` futura: até 2027-11-04 (pagar) e 2032-09-04 (receber).
- 3.604 registros `aguard` em receber com valor, vencimento e conta **nulos**.

---

## 5. Identidade e idempotência

```
source_system = LEGACY_PAYABLE     external_id = contas.lancamentos.id
source_system = LEGACY_RECEIVABLE  external_id = contasareceber.lancamentos.id
```

`financial_titles` tem UNIQUE em `(source_system_id, external_id)`, então
reimportar reconhece em vez de duplicar. Liquidações usam
`external_id = 'baixa-<id>'` com UNIQUE em `(source_system_id, external_id)`.

**Nunca** usar `ndocumento` como identidade: repete milhares de vezes.

---

## 6. Segurança do acesso

- `tools/integration/origin-reader.php` recusa qualquer instrução que não seja
  SELECT/SHOW/DESCRIBE/EXPLAIN, bloqueia palavras de escrita em qualquer posição,
  recusa múltiplas instruções e abre a sessão com
  `SET SESSION TRANSACTION READ ONLY`. A trava foi testada contra UPDATE,
  INSERT, DELETE, DROP, TRUNCATE, statement empilhado e `INTO OUTFILE`.
- Credenciais lidas de `contasdb.php` / `contasareceberdb.php`. **Não constam
  aqui.**
- Observação de segurança, sem ação tomada: os dois sistemas conectam com
  usuário administrativo e **senha vazia**, direto ao servidor de produção. Vale
  uma revisão de infraestrutura, independente deste projeto.
