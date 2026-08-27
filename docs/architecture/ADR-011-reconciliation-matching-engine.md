# ADR-011 — Motor determinístico de matching

- Status: aceito
- Data: 2026-08-13
- Versão inicial: `rules-v1`

## Contexto e decisão

A Fase 5 precisa ordenar hipóteses de conciliação sem transformar probabilidade em fato financeiro. Foi criado um motor determinístico, separado da interface e do serviço de confirmação. A mesma entrada, configuração e versão produzem a mesma assinatura, score e evidência. Não há IA, LLM, aleatoriedade ou aprendizado oculto.

O motor pré-filtra por conta da sessão, período da transação, direção PAYABLE/DEBIT ou RECEIVABLE/CREDIT, moeda, disponibilidade positiva e janela de datas. O pool é limitado antes da composição. Ele gera 1:1, 1:N e N:1; N:N permanece exclusivamente manual. Subconjuntos têm tamanho e quantidade limitados.

## Regras confirmadas

- geração nunca cria match, settlement, ajuste, tarifa, estorno ou fechamento;
- valor sozinho é indício, não certeza;
- identificador forte pesa mais que semelhança textual;
- data influencia, mas não confirma;
- candidatos guardam parcela concreta e valores propostos;
- aceite humano chama `ManualReconciliationService`, que trava e revalida todos os fatos;
- falha de revalidação marca o candidato `STALE` em transação separada;
- assinatura impede duplicata na mesma sessão/versão/composição;
- evidência persiste códigos e impactos, não documentos ou descrições completos.

## Configuração técnica provisória

Todos os valores abaixo estão centralizados em `config/reconciliation_matching.php`. Ainda não foram validados pelo negócio:

| Sinal | Peso rules-v1 |
|---|---:|
| valor exato | 30 |
| documento de negócio exato | 30 |
| documento em referência | 20 |
| documento da contraparte exato | 30 |
| nome da contraparte exato | 15 |
| sobreposição de tokens do nome | 8 |
| mesma data | 12 |
| distância até 3 dias | 8 |
| dentro da janela | 3 |

Score é inteiro, limitado a 100. Bandas: HIGH ≥ 75, MEDIUM ≥ 50 e LOW abaixo de 50. Score mínimo exibido: 25. Janela: 10 dias. Pool por lado: 200. Máximo por recurso: 8. Composição: até 3 itens, pool local 12 e no máximo 100 subconjuntos avaliados por recurso. Ambiguidade técnica: diferença até 5 pontos. Esses parâmetros exigem calibração com dados sintéticos/homologados e nova `engine_version` quando sua semântica mudar.

## Normalização e evidência

`ReconciliationTextNormalizer` remove acentos, normaliza caixa/espaço, cria tokens auxiliares e normaliza identificadores. CPF/CNPJ só é considerado quando a origem disponível contém exatamente 11 ou 14 dígitos; a Fase 5 não altera o schema do título para inventar documento de parte. `ReconciliationCandidateScorer` retorna score, banda e lista de `{code, impact}`. `ReconciliationMatchingEngine` seleciona, compõe, limita e persiste.

## Consequências e limites

Há explicabilidade e repetibilidade, mas não “certeza”. Dois candidatos fortes permanecem visíveis e geram ambiguidade; o motor não desempata arbitrariamente. Combinações são limitadas para evitar explosão combinatória. Compatibilidade e concorrência reais no MariaDB 10.1 continuam pendentes de homologação. Auto-confirm permanece proibido.
