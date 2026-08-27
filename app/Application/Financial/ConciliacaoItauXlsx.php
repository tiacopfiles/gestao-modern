<?php

namespace App\Application\Financial;

use App\Domain\Financial\Enums\PeriodStatementSection;
use App\Domain\Financial\Money;
use App\Models\BankAccount;
use App\Models\PeriodStatement;
use App\Models\PeriodStatementLine;
use Carbon\CarbonInterface;
use RuntimeException;
use ZipArchive;

/**
 * Exporta a conciliação no formato EXATO das planilhas que a equipe financeira
 * mantém à mão em `K:\GERAL\TI\Conciliação Itaú <empresa>.xlsx`.
 *
 * "Exato" aqui não é força de expressão: larguras de coluna, fontes, bordas,
 * formato de moeda e o layout do cabeçalho foram lidos do arquivo real e estão
 * reproduzidos célula a célula, para que o arquivo baixado possa ser aberto ao
 * lado do original sem ninguém notar a diferença — com exceções pedidas pelo
 * usuário em 2026-08-27 e documentadas nos pontos abaixo:
 *
 *  - o saldo da coluna G é FÓRMULA (`=G4+E5-F5`), como no original, e não valor
 *    congelado. Quem editar uma linha no Excel vê o saldo se refazer, que é o
 *    jeito como a equipe trabalha hoje;
 *  - o original tem um bloco final "em aberto" (títulos que ainda não caíram
 *    no banco); esta planilha NÃO traz mais esse bloco — só movimento de fato;
 *  - a Saída (coluna F) é preta aqui, vermelha no original; e o saldo do
 *    ÚLTIMO movimento de cada dia ganha fundo verde, que não existe no
 *    original.
 *
 * Não usa biblioteca externa de planilha de propósito: o servidor não tem
 * composer (o `vendor/` é enviado pronto), e um XLSX é um zip de XML que o
 * PHP daqui já sabe montar — `ZipArchive` e `XMLWriter` estão disponíveis.
 * Escrever o XML à mão também é o que dá controle de formatação no detalhe.
 */
class ConciliacaoItauXlsx
{
    /**
     * Estilos (índices de `cellXfs`), na ordem em que são declarados abaixo.
     * Os números não são arbitrários: são a posição no `<cellXfs>` do
     * styles.xml, e é assim que cada célula referencia seu formato.
     */
    private const ESTILO_PADRAO = 0;

    private const ESTILO_TITULO = 1;          // linhas 1 e 2: Arial 12, centralizado

    private const ESTILO_CABECALHO = 2;       // linha 3: Arial 10 negrito

    private const ESTILO_DATA = 3;            // coluna A

    private const ESTILO_CENTRALIZADO = 4;    // colunas B e C

    private const ESTILO_TEXTO = 5;           // coluna D

    private const ESTILO_MOEDA = 6;           // colunas E e G

    /**
     * Coluna F. No arquivo original a Saída é vermelha; por pedido do usuário
     * em 2026-08-27 a planilha gerada usa preto aqui — é uma divergência
     * proposital do original, não um erro de fidelidade.
     */
    private const ESTILO_MOEDA_SAIDA = 7;

    /**
     * Os quatro abaixo existem porque a planilha original não é uniforme, e
     * "exatamente igual" inclui as irregularidades dela: o título da coluna
     * Data carrega o formato de data, a coluna Histórico vem sem borda de topo,
     * a coluna Saída vem sem borda de base, e a linha do saldo de abertura tem
     * preenchimento branco e bordas próprias. Nada disso muda o que se vê — a
     * célula vizinha fecha a grade —, mas replicar evita que a comparação
     * lado a lado acuse diferença.
     */
    private const ESTILO_CABECALHO_DATA = 8;  // A3

    private const ESTILO_ABERTURA_SEM_BASE = 9;   // A4 e B4

    private const ESTILO_ABERTURA_CENTRO = 10;    // C4

    private const ESTILO_ABERTURA_TEXTO = 11;     // D4

    private const ESTILO_ABERTURA_MOEDA = 12;     // E4

    /**
     * Saldo (coluna G) do ÚLTIMO movimento de cada dia, com fundo verde —
     * pedido do usuário em 2026-08-27, não existe no arquivo original.
     */
    private const ESTILO_SALDO_ULTIMO_DIA = 13;

    /** Linhas em branco já formatadas no fim, para a equipe seguir anotando à mão. */
    private const LINHAS_EM_BRANCO_NO_FIM = 25;

    private const MESES = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
    ];

    /** Devolve o conteúdo binário do .xlsx. */
    public function gerar(PeriodStatement $statement): string
    {
        $statement->loadMissing('lines');

        // Pedido do usuário em 2026-08-27: a planilha baixada não traz mais o
        // bloco de "em aberto" (títulos que ainda não caíram no banco) — só o
        // que já é movimento de fato. O original tem esse bloco; aqui é uma
        // divergência proposital, não erro de fidelidade.
        $ledger = $statement->lines
            ->reject(fn (PeriodStatementLine $l): bool => $l->section === PeriodStatementSection::Pending)
            ->sortBy('line_number');

        $planilha = $this->planilhaXml($statement, $ledger);

        return $this->zip([
            '[Content_Types].xml' => $this->contentTypesXml(),
            '_rels/.rels' => $this->relsXml(),
            'docProps/core.xml' => $this->coreXml($statement),
            'docProps/app.xml' => $this->appXml(),
            'xl/workbook.xml' => $this->workbookXml($this->nomeDaAba($statement)),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelsXml(),
            'xl/styles.xml' => $this->stylesXml(),
            'xl/theme/theme1.xml' => $this->themeXml(),
            'xl/worksheets/sheet1.xml' => $planilha,
        ]);
    }

    public function nomeDoArquivo(PeriodStatement $statement): string
    {
        $empresa = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $statement->account_name);
        $empresa = trim((string) $empresa, '-');

        $inicio = $statement->period_start->format('Y-m-d');
        $fim = $statement->period_end->format('Y-m-d');
        $periodo = $inicio === $fim ? $inicio : "{$inicio}_a_{$fim}";

        return "Conciliacao-{$empresa}-{$periodo}.xlsx";
    }

    /** "Agosto-2026" — a mesma convenção de nome de aba da planilha original. */
    private function nomeDaAba(PeriodStatement $statement): string
    {
        $inicio = $statement->period_start;

        return self::MESES[(int) $inicio->format('n')].'-'.$inicio->format('Y');
    }

    /**
     * O rótulo do banco, que na planilha é a segunda linha do cabeçalho.
     *
     * Sem conta bancária vinculada cai para `account_bank` (o campo do
     * cadastro legado) e, na falta dos dois, deixa em branco — inventar um
     * banco no cabeçalho de uma conciliação seria pior que não informar.
     */
    private function rotuloDoBanco(PeriodStatement $statement): string
    {
        if ($statement->bank_account_id !== null) {
            $conta = BankAccount::query()->find($statement->bank_account_id);
            if ($conta !== null) {
                return $conta->fullLabel();
            }
        }

        return (string) ($statement->account_bank ?? '');
    }

    /**
     * A linha 1 da planilha original traz a razão social completa, não o nome
     * curto que o sistema usa internamente (`account_name` = "Acop Files"). A
     * razão social não existe em nenhum campo do banco de dados — foi lida à
     * mão nas 3 planilhas de `K:\GERAL\TI\` e vale exatamente o que está lá,
     * erro de digitação incluso (Duemagem: "Documemtos").
     */
    private const RAZAO_SOCIAL = [
        'Acop Files' => 'Acop Files Organização e Guarda de Documentos Ltda',
        'Global Box' => 'Global Box Tecnologia e Segurança da Informação Eirelli EPP',
        'Duemagem' => 'Duemagem Digitação e Administração de Documemtos Ltda',
    ];

    private function razaoSocial(PeriodStatement $statement): string
    {
        $nome = (string) $statement->account_name;

        return self::RAZAO_SOCIAL[$nome] ?? $nome;
    }

    // ------------------------------------------------------------- Planilha ---

    private function planilhaXml(PeriodStatement $statement, $ledger): string
    {
        $linhas = [];

        // Linhas 1 e 2: empresa e conta bancária, cada uma mesclada de A até G.
        // `array_merge` e não `+`: a união de arrays do PHP mantém a chave do
        // lado esquerdo e descartaria silenciosamente a primeira célula vazia.
        $linhas[] = $this->linhaXml(1, array_merge(
            [$this->celulaTexto('A1', $this->razaoSocial($statement), self::ESTILO_TITULO)],
            $this->celulasVazias(1, 'B', 'G', self::ESTILO_TITULO),
        ), 44.25);
        $linhas[] = $this->linhaXml(2, array_merge(
            [$this->celulaTexto('A2', $this->rotuloDoBanco($statement), self::ESTILO_TITULO)],
            $this->celulasVazias(2, 'B', 'G', self::ESTILO_TITULO),
        ), 15.75);

        // Linha 3: os títulos das colunas, como no original.
        $cabecalhos = ['A' => 'Data', 'B' => 'Nº.Doc.', 'C' => 'ID', 'D' => 'Histórico',
            'E' => 'Entrada', 'F' => 'Saída', 'G' => 'Saldo Final'];
        $celulas = [];
        foreach ($cabecalhos as $col => $texto) {
            $celulas[] = $this->celulaTexto(
                $col.'3',
                $texto,
                $col === 'A' ? self::ESTILO_CABECALHO_DATA : self::ESTILO_CABECALHO,
            );
        }
        $linhas[] = $this->linhaXml(3, $celulas);

        // Linha 4: o saldo de abertura. O texto vai em D e o valor em G, que é
        // de onde a primeira fórmula de saldo parte.
        $diaAnterior = $statement->period_start->copy()->subDay();
        $linhas[] = $this->linhaXml(4, [
            $this->celulaVazia('A4', self::ESTILO_ABERTURA_SEM_BASE),
            $this->celulaVazia('B4', self::ESTILO_ABERTURA_SEM_BASE),
            $this->celulaVazia('C4', self::ESTILO_ABERTURA_CENTRO),
            $this->celulaTexto('D4', 'Saldo em '.$diaAnterior->format('d/m/Y'), self::ESTILO_ABERTURA_TEXTO),
            $this->celulaVazia('E4', self::ESTILO_ABERTURA_MOEDA),
            $this->celulaVazia('F4', self::ESTILO_MOEDA_SAIDA),
            $this->celulaNumero('G4', $this->reais($statement->opening_balance_cents), self::ESTILO_MOEDA),
        ]);

        // Um movimento por dia às vezes se repete; o pedido é destacar só o
        // ÚLTIMO de cada data. `$ledger` já vem ordenado por `line_number`
        // (cronológico), então o último de cada grupo por data é o correto.
        $idsUltimoDoDia = array_flip($ledger
            ->groupBy(fn (PeriodStatementLine $l): string => $l->movement_date->format('Y-m-d'))
            ->map(fn ($grupo) => $grupo->last()->id)
            ->all());

        // A partir da 5: o movimento, com o saldo corrido em fórmula e também
        // com o valor calculado em cache. O Excel usa o cache para mostrar o
        // saldo imediatamente, antes mesmo de recalcular a pasta de trabalho.
        $r = 5;
        $saldoCorrenteCentavos = (int) $statement->opening_balance_cents;
        foreach ($ledger as $linha) {
            $saldoCorrenteCentavos += (int) ($linha->amount_in_cents ?? 0);
            $saldoCorrenteCentavos -= (int) ($linha->amount_out_cents ?? 0);
            $estiloSaldo = isset($idsUltimoDoDia[$linha->id])
                ? self::ESTILO_SALDO_ULTIMO_DIA
                : self::ESTILO_MOEDA;

            $linhas[] = $this->linhaXml($r, [
                $this->celulaData('A'.$r, $linha->movement_date),
                $this->celulaTexto('B'.$r, (string) $linha->document_number, self::ESTILO_CENTRALIZADO),
                $this->celulaTexto('C'.$r, (string) $linha->origin_id, self::ESTILO_CENTRALIZADO),
                $this->celulaTexto('D'.$r, (string) $linha->history, self::ESTILO_TEXTO),
                $linha->amount_in_cents !== null
                    ? $this->celulaNumero('E'.$r, $this->reais($linha->amount_in_cents), self::ESTILO_MOEDA)
                    : $this->celulaVazia('E'.$r, self::ESTILO_MOEDA),
                $linha->amount_out_cents !== null
                    ? $this->celulaNumero('F'.$r, $this->reais($linha->amount_out_cents), self::ESTILO_MOEDA_SAIDA)
                    : $this->celulaVazia('F'.$r, self::ESTILO_MOEDA_SAIDA),
                $this->celulaFormula(
                    'G'.$r,
                    sprintf('G%d+E%d-F%d', $r - 1, $r, $r),
                    $this->reais($saldoCorrenteCentavos),
                    $estiloSaldo,
                ),
            ]);
            $r++;
        }

        // Espaço já formatado para a equipe continuar anotando à mão, que é o
        // que ela faz hoje no fim da aba do mês corrente.
        for ($i = 0; $i < self::LINHAS_EM_BRANCO_NO_FIM; $i++) {
            $linhas[] = $this->linhaXml($r, [
                $this->celulaVazia('A'.$r, self::ESTILO_DATA),
                $this->celulaVazia('B'.$r, self::ESTILO_CENTRALIZADO),
                $this->celulaVazia('C'.$r, self::ESTILO_CENTRALIZADO),
                $this->celulaVazia('D'.$r, self::ESTILO_TEXTO),
                $this->celulaVazia('E'.$r, self::ESTILO_MOEDA),
                $this->celulaVazia('F'.$r, self::ESTILO_MOEDA_SAIDA),
                $this->celulaVazia('G'.$r, self::ESTILO_MOEDA),
            ]);
            $r++;
        }

        // Larguras medidas no arquivo original, com as casas decimais que ele traz.
        $larguras = ['A' => 14.140625, 'B' => 9.42578125, 'C' => 11.85546875, 'D' => 75.5703125,
            'E' => 15.85546875, 'F' => 14.28515625, 'G' => 14.28515625];
        $cols = '';
        $i = 1;
        foreach ($larguras as $largura) {
            $cols .= sprintf('<col min="%d" max="%d" width="%s" customWidth="1"/>', $i, $i, $largura);
            $i++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetPr><pageSetUpPr fitToPage="0"/></sheetPr>'
            .'<dimension ref="A1:G'.max(4, $r - 1).'"/>'
            .'<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .'<cols>'.$cols.'</cols>'
            .'<sheetData>'.implode('', $linhas).'</sheetData>'
            .'<mergeCells count="2"><mergeCell ref="A1:G1"/><mergeCell ref="A2:G2"/></mergeCells>'
            .'<pageMargins left="0.51181102362204722" right="0.51181102362204722" '
            .'top="0.78740157480314965" bottom="0.78740157480314965" header="0.31496062992125984" '
            .'footer="0.31496062992125984"/>'
            .'<pageSetup paperSize="9" orientation="portrait"/>'
            .'</worksheet>';
    }

    // -------------------------------------------------------------- Células ---

    /** @param array<int,string> $celulas */
    private function linhaXml(int $numero, array $celulas, ?float $altura = null): string
    {
        $attrs = 'r="'.$numero.'"';
        if ($altura !== null) {
            $attrs .= ' ht="'.$altura.'" customHeight="1"';
        }

        return '<row '.$attrs.'>'.implode('', $celulas).'</row>';
    }

    /** @return array<int,string> */
    private function celulasVazias(int $linha, string $de, string $ate, int $estilo): array
    {
        $celulas = [];
        for ($c = ord($de); $c <= ord($ate); $c++) {
            $celulas[] = $this->celulaVazia(chr($c).$linha, $estilo);
        }

        return $celulas;
    }

    private function celulaVazia(string $ref, int $estilo): string
    {
        return sprintf('<c r="%s" s="%d"/>', $ref, $estilo);
    }

    /**
     * Texto vai como `inlineStr` em vez de entrar na tabela de strings
     * compartilhadas: evita um segundo arquivo XML e a contagem de referências,
     * e o Excel lê os dois do mesmo jeito.
     */
    private function celulaTexto(string $ref, string $valor, int $estilo): string
    {
        if ($valor === '') {
            return $this->celulaVazia($ref, $estilo);
        }

        return sprintf(
            '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
            $ref,
            $estilo,
            $this->escapar($valor),
        );
    }

    private function celulaNumero(string $ref, string $valor, int $estilo): string
    {
        return sprintf('<c r="%s" s="%d"><v>%s</v></c>', $ref, $estilo, $valor);
    }

    private function celulaFormula(string $ref, string $formula, string $valorCalculado, int $estilo): string
    {
        return sprintf(
            '<c r="%s" s="%d"><f>%s</f><v>%s</v></c>',
            $ref,
            $estilo,
            $this->escapar($formula),
            $valorCalculado,
        );
    }

    private function celulaData(string $ref, ?CarbonInterface $data): string
    {
        if ($data === null) {
            return $this->celulaVazia($ref, self::ESTILO_DATA);
        }

        return $this->celulaNumero($ref, (string) $this->serialExcel($data), self::ESTILO_DATA);
    }

    /**
     * Data no serial do Excel. A época é 1899-12-30, e não 1900-01-01, porque
     * o Excel herdou do Lotus a crença de que 1900 foi bissexto: contar a
     * partir do dia 30 é o ajuste que faz as datas reais baterem.
     */
    private function serialExcel(CarbonInterface $data): int
    {
        $epoca = $data->copy()->setDate(1899, 12, 30)->startOfDay();

        return (int) $epoca->diffInDays($data->copy()->startOfDay());
    }

    /** Centavos inteiros viram o decimal que o Excel guarda. */
    private function reais(int|string|null $centavos): string
    {
        return number_format((float) Money::fromCents((int) $centavos), 2, '.', '');
    }

    private function escapar(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // ---------------------------------------------------- Estrutura do xlsx ---

    /**
     * Os estilos, copiados do arquivo original.
     *
     * `numFmtId="164"` é o formato de moeda que a planilha usa em todas as
     * colunas de valor, e `numFmtId="14"` é a data curta embutida do Excel —
     * a mesma que o original aplica na coluna A, e que aparece como dd/mm/aaaa
     * em máquina configurada em português.
     */
    private function stylesXml(): string
    {
        $moeda = '_(&quot;R$ &quot;* #,##0.00_);_(&quot;R$ &quot;* \(#,##0.00\);'
            .'_(&quot;R$ &quot;* &quot;-&quot;??_);_(@_)';

        $borda = fn (bool $topo, bool $base): string => '<border>'
            .'<left style="thin"><color indexed="64"/></left>'
            .'<right style="thin"><color indexed="64"/></right>'
            .($topo ? '<top style="thin"><color indexed="64"/></top>' : '<top/>')
            .($base ? '<bottom style="thin"><color indexed="64"/></bottom>' : '<bottom/>')
            .'<diagonal/></border>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="'.$moeda.'"/></numFmts>'
            .'<fonts count="4">'
            .'<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/><scheme val="minor"/></font>'
            .'<font><sz val="12"/><color theme="1"/><name val="Arial"/><family val="2"/></font>'
            .'<font><b/><sz val="10"/><color theme="1"/><name val="Arial"/><family val="2"/></font>'
            // Fonte 3: era vermelha para a Saída; virou preta (tema 1) em
            // 2026-08-27 por pedido do usuário. Mantida como fonte própria
            // (em vez de reaproveitar a 0) para não perder o índice referenciado
            // pelo estilo 7.
            .'<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/><scheme val="minor"/></font>'
            .'</fonts>'
            // fill 2 é o branco da linha do saldo de abertura (tema 0);
            // 3 é o verde do saldo do último movimento de cada dia (pedido
            // do usuário em 2026-08-27, não existe no arquivo original).
            .'<fills count="4"><fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor theme="0"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFC6E0B4"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="4">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'  // 0 sem borda
            .$borda(true, true)                                            // 1 completa
            .$borda(false, true)                                           // 2 sem topo (coluna Histórico)
            .$borda(true, false)                                           // 3 sem base (coluna Saída)
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="14">'
            // 0 padrão
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            // 1 título (linhas 1 e 2)
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" '
            .'applyAlignment="1"><alignment horizontal="center"/></xf>'
            // 2 cabeçalho (linha 3)
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" '
            .'applyAlignment="1"><alignment horizontal="center"/></xf>'
            // 3 data
            .'<xf numFmtId="14" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" '
            .'applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            // 4 centralizado
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" '
            .'applyAlignment="1"><alignment horizontal="center"/></xf>'
            // 5 texto (Histórico: sem borda de topo, como no original)
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="2" xfId="0" applyBorder="1"/>'
            // 6 moeda
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            // 7 moeda da Saída, preta (sem borda de base, como no original —
            // o original usa vermelho, ver comentário da fonte 3 acima)
            .'<xf numFmtId="164" fontId="3" fillId="0" borderId="3" xfId="0" applyNumberFormat="1" '
            .'applyFont="1" applyBorder="1"/>'
            // 8 título da coluna Data (carrega o formato de data no original)
            .'<xf numFmtId="14" fontId="2" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" '
            .'applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            // 9 abertura A4/B4: sem base, com preenchimento
            .'<xf numFmtId="0" fontId="0" fillId="2" borderId="3" xfId="0" applyFill="1" applyBorder="1" '
            .'applyAlignment="1"><alignment horizontal="center"/></xf>'
            // 10 abertura C4
            .'<xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0" applyFill="1" applyBorder="1" '
            .'applyAlignment="1"><alignment horizontal="center"/></xf>'
            // 11 abertura D4
            .'<xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'
            // 12 abertura E4
            .'<xf numFmtId="164" fontId="0" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" '
            .'applyFill="1" applyBorder="1"/>'
            // 13 saldo (G) do último movimento de cada dia, fundo verde
            .'<xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" '
            .'applyFill="1" applyBorder="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .'</Types>';
    }

    private function relsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    /**
     * O tema, copiado do próprio arquivo do Itaú.
     *
     * Não é enfeite: `styles.xml` usa `theme="1"` (texto) e `theme="0"` (fundo),
     * e uma cor de tema sem a parte do tema faz o **Excel recusar o arquivo**
     * como corrompido — mesmo o zip estando íntegro. Leitores tolerantes como
     * o openpyxl abrem assim mesmo, então o defeito passa despercebido em
     * teste automatizado; foi exatamente o que aconteceu aqui.
     */
    private function themeXml(): string
    {
        $caminho = resource_path('xlsx/theme1.xml');

        if (! is_file($caminho)) {
            throw new RuntimeException('Tema da planilha ausente: '.$caminho);
        }

        return (string) file_get_contents($caminho);
    }

    private function coreXml(PeriodStatement $statement): string
    {
        $agora = now()->toIso8601ZuluString();

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties '
            .'xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            .'xmlns:dcterms="http://purl.org/dc/terms/" '
            .'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
            .'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.$this->escapar('Conciliação '.$statement->account_name).'</dc:title>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$agora.'</dcterms:created>'
            .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$agora.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    private function appXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            .'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>Gestao Financeira Acop</Application>'
            .'</Properties>';
    }

    private function workbookXml(string $nomeDaAba): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->escapar($nomeDaAba).'" sheetId="1" r:id="rId1"/></sheets>'
            // As fórmulas de saldo saem sem valor em cache (só `<f>`, sem `<v>`).
            // Sem este `fullCalcOnLoad`, o Excel pode mostrar a coluna zerada
            // até alguém forçar o recálculo.
            .'<calcPr calcId="0" fullCalcOnLoad="1"/>'
            .'</workbook>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>'
            .'</Relationships>';
    }

    /** @param array<string,string> $arquivos */
    private function zip(array $arquivos): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($caminho === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário da planilha.');
        }

        $zip = new ZipArchive;
        if ($zip->open($caminho, ZipArchive::OVERWRITE) !== true) {
            @unlink($caminho);
            throw new RuntimeException('Não foi possível montar o arquivo da planilha.');
        }

        foreach ($arquivos as $nome => $conteudo) {
            $zip->addFromString($nome, $conteudo);
        }
        $zip->close();

        $binario = file_get_contents($caminho);
        @unlink($caminho);

        if ($binario === false) {
            throw new RuntimeException('Não foi possível ler o arquivo da planilha.');
        }

        return $binario;
    }
}
