# Guia de demonstração local — Gestão + Conciliação

Roteiro para abrir o sistema e demonstrá-lo para outra pessoa, sem precisar
entender o código.

Tudo aqui usa **SQLite local** e **dados 100% fictícios**. Nenhum passo toca banco
real, `avt_*` de produção, ou os sistemas legados protegidos.

---

## 1. Iniciar o sistema (3 comandos)

Abra o PowerShell na pasta `gestao-modern`:

```powershell
php tools\demo\setup-demo-scenarios.php
php artisan serve
```

O primeiro comando **recria os dados de demonstração do zero** (pode rodar
quantas vezes quiser — sempre volta ao mesmo ponto de partida). O segundo sobe o
servidor.

> **Primeira vez nesta máquina?** Antes dos dois comandos acima, rode uma vez:
> ```powershell
> composer install
> php artisan migrate
> php tools\demo\setup-sqlite.php
> ```
> E confirme que o `.env` tem as linhas de `RECONCILIATION_*` ligadas (seção 9).

## 2. Endereço e acesso

```text
URL:     http://127.0.0.1:8000
Usuário: demo@acop.local
Senha:   Demo@Acop2026
```

Para encerrar: `Ctrl+C` na janela do `php artisan serve`.

---

## 3. O que já vem pronto

O seed cria três sessões de conciliação, cada uma em um estado diferente, para
você demonstrar o ciclo inteiro sem preparar nada:

| Sessão | Conta | Período | Estado |
|---|---|---|---|
| **#1** | Conta Operacional | mês atual | **Em operação** — tem match confirmado, uma sugestão automática pendente, divergências abertas e uma já justificada |
| **#2** | Conta Reserva | mês anterior | **Pronta para fechar** — tudo conciliado, divergências justificadas, sem impedimento |
| **#3** | Conta Reserva | dois meses atrás | **Fechada** — já tem fechamento com hash e métricas, pronta para reabrir |
| **#4** | Conta Reserva | três meses atrás | **Cenário de extrato** — ciclo completo: título → pago/recebido → fato bancário → conciliado |

Também existem títulos a pagar e a receber pendentes, extratos importados e
lançamentos legados.

---

## 4. Roteiro de demonstração — 5 minutos

### Passo 1 — Dashboard (30s)
Entre com o usuário acima. O dashboard mostra a posição do financeiro legado
(a receber, a pagar, vencidos) e, mais abaixo, o painel **"Conciliação v2 —
núcleo moderno"** com sessões abertas, matches confirmados, divergências e
fechamentos.

### Passo 2 — Contas a pagar e a receber (45s)
Menu **Contas a pagar** → a lista abre com filtros e totais.
Clique em **+ Novo** para mostrar o cadastro (fornecedor, documento, vencimento,
valor). Salve um título de exemplo — ele aparece na lista imediatamente.
Repita em **Contas a receber** se quiser.

### Passo 3 — Extratos bancários (45s)
Menu **Extratos bancários** → mostra os fatos bancários importados, com data,
descrição, entrada/saída e valor, além do histórico de lotes de importação.

Clique em **+ Importar extrato OFX**, escolha uma conta e envie um arquivo
`.ofx`. Use um dos exemplos em `tests\Fixtures\Banking\statement-valid.ofx`.
O sistema informa quantos fatos são novos e quantos já existiam.

> **Demonstre a proteção contra duplicidade:** importe o *mesmo arquivo* de novo.
> O sistema responde que já havia sido importado e **não duplica nada**.

### Passo 3.5 — Títulos e a ação rápida (45s)
Menu **Títulos** → mostra tudo que veio do Contas a Pagar / Contas a Receber,
com **em aberto** e **realizado** no topo.

Num título em aberto, clique em **"Marcar como pago"** (ou recebido). Em um
clique o título vira PAGO.

> **Frase-chave:** *"marcar como pago aqui não mexe no sistema de origem, e não
> concilia — só diz que o Gestão considera esse título realizado."*

### Passo 3.6 — Extrato com saldo (60s) ⭐
Menu **Extrato** → escolha **Conta Reserva**, o período de **três meses atrás**,
e digite **10.000,00** em "Saldo inicial".

A tela mostra exatamente o que o sistema antigo mostrava, e mais:

```
Saldo inicial                              R$ 10.000,00
05/05  Pagamento fornecedor  − R$ 1.000,00  R$  9.000,00  CONCILIADO
10/05  Recebimento cliente   + R$ 2.500,00  R$ 11.500,00  CONCILIADO
Saldo final                                R$ 11.500,00
```

Quanto tinha, quanto entrou, quanto saiu, quanto ficou — e qual movimento
produziu cada saldo. Tem também exportação em CSV.

### Passo 4 — Conciliar (90s)
Menu **Conciliação v2** → abra a **Sessão #1**.

A tela tem duas listas lado a lado: **títulos** e **transações bancárias**, cada
uma com o valor total, o quanto já foi conciliado e o quanto ainda está
disponível.

- **Match manual:** marque um título e uma transação de mesmo valor, confira os
  valores no campo "Alocar" e clique em confirmar. O match aparece no histórico.
- **Sugestão automática:** clique em **"Gerar sugestões e divergências"**. O
  motor propõe pares e aponta o que não bate. Abra uma sugestão, veja a
  evidência (por que ele sugeriu) e clique em aceitar.
- **Desfazer:** abra um match e use "Desfazer" informando o motivo. O histórico
  é preservado — nada é apagado.

### Passo 5 — Divergência (45s)
Ainda na Sessão #1, abra uma **divergência**. Ela explica o motivo (crédito sem
título correspondente, valor divergente etc.).

Registre uma **justificativa** — por exemplo, "tarifa bancária, sem título".
A divergência sai de "aberta" e deixa de bloquear o fechamento.

### Passo 6 — Fechar o período (60s)
Vá em **Conciliação v2 → Sessão #2** (a que já está pronta).

Clique em **Preparar fechamento**. A tela mostra a situação: o que será
incluído, as métricas e se existe algum impedimento.

Confirme e **feche**. O sistema gera o fechamento com um **hash** (a "impressão
digital" do que foi fechado) e as métricas do período.

> **Mostre que fechado é fechado:** volte para a sessão e tente conciliar algo.
> O sistema recusa: *"A sessão está fechada. Reabra-a explicitamente antes de
> realizar esta ação."* Isso é uma regra do sistema, não só um botão escondido.

### Passo 7 — Reabrir (45s)
Na tela do fechamento, clique em **Reabrir**. O motivo é **obrigatório** —
digite algo como "ajuste solicitado pelo financeiro".

O fechamento anterior **não é apagado**: ele fica com status "reaberto" e o
registro de quem reabriu, quando e por quê.

Faça um ajuste e **feche de novo**. Em **Histórico de fechamentos** aparecem
agora dois fechamentos encadeados (sequência 1 e 2), o primeiro preservado.

### Passo 8 — Auditoria (30s)
Menu **Auditoria** → mostra quem fez o quê, quando e em qual recurso, com o
identificador de correlação de cada operação.

---

## 5. Frases úteis durante a demonstração

- *"Conciliar aqui não dá baixa em título e não mexe no extrato."* — a
  conciliação liga as informações, sem alterar os fatos financeiros.
- *"O fechamento é reproduzível."* — o hash permite provar depois que o conteúdo
  fechado é exatamente aquele.
- *"Reabrir não apaga histórico."* — a versão anterior continua consultável.
- *"O sistema antigo continua funcionando."* — o menu **Conciliação** (sem o
  "v2") é o fluxo legado, intacto.

---

## 6. Recomeçar do zero

```powershell
php tools\demo\setup-demo-scenarios.php
```

Recria as três sessões no estado original. Não apaga usuários nem contas.

Para zerar completamente, apague `database\database.sqlite` e refaça a seção 1.

---

## 7. Se algo der errado

| Sintoma | Causa provável | Solução |
|---|---|---|
| Menu não mostra "Conciliação v2" nem "Extratos bancários" | Flags desligadas no `.env` | Ver seção 9 e rodar `php artisan config:clear` |
| Tela responde "não encontrado" (404) | Mesma coisa: flag desligada | Idem |
| Tela responde "sem permissão" (403) | Seu usuário não está nas listas de IDs | Ver seção 9 |
| Fechamento recusado | Existe divergência aberta | Justifique a divergência primeiro |
| Não consigo conciliar uma transação | Ela está fora do período da sessão, ou já conciliada | Confira a coluna "disponível" |

---

## 8. Limites conhecidos desta demonstração

- **Não é ambiente de produção.** As permissões estão liberadas para o usuário
  demo e as regras de governança usam defaults temporários de MVP.
- **Não existe exportação de relatório** de fechamento (PDF/CSV) — o formato
  ainda não foi definido pelo financeiro. (O **extrato** tem exportação CSV.)
- **O saldo inicial do extrato é digitado por você**, não é saldo contábil
  oficial. O Gestão ainda não tem essa noção; depende de decisão de negócio.
- **Contas a Pagar e Contas a Receber ainda não enviam dados automaticamente.**
  Os títulos entram pela API ou pelo seed. Ver
  `docs/integration/INTEGRACAO_CONTAS_PAGAR_RECEBER.md`.
- As telas **Contas a pagar/receber** do menu operam o cadastro **legado**; a
  tela **Títulos** opera o núcleo moderno que alimenta a conciliação.

---

## 9. Configuração do `.env` para a demonstração

Estas linhas precisam existir no `.env` (o `1` é o ID do usuário demo):

```dotenv
RECONCILIATION_V2_ENABLED=true
RECONCILIATION_MATCHING_ENABLED=true
RECONCILIATION_CLOSING_ENABLED=true
RECONCILIATION_V2_VIEW_USER_IDS=1
RECONCILIATION_V2_MANAGE_USER_IDS=1
RECONCILIATION_CLOSE_USER_IDS=1
RECONCILIATION_REOPEN_USER_IDS=1
```

Depois de mudar o `.env`, rode `php artisan config:clear`.

Em produção estas flags nascem **desligadas** e as listas **vazias**, de
propósito — o módulo só aparece para quem for explicitamente autorizado.
