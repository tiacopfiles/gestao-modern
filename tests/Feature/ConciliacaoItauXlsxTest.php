<?php

namespace Tests\Feature;

use App\Application\Financial\ConciliacaoItauXlsx;
use App\Domain\Financial\Enums\PeriodStatementSection;
use App\Models\BankAccount;
use App\Models\Conta;
use App\Models\PeriodStatement;
use App\Models\PeriodStatementLine;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ZipArchive;

/**
 * Download da conciliação no formato das planilhas do Itaú.
 *
 * O pedido foi literal — "quero que fique EXATAMENTE igual" a
 * `K:\GERAL\TI\Conciliação Itaú Acop Files.xlsx` — então o que este teste
 * trava não é só "gerou um arquivo": são as decisões de formato lidas do
 * arquivo real (larguras, fonte, moeda, o saldo em fórmula) e o layout de
 * dois blocos, movimento e "em aberto".
 */
class ConciliacaoItauXlsxTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    private PeriodStatement $statement;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome')->nullable();
            $table->string('username');
            $table->string('password')->nullable();
            $table->boolean('comercial')->default(false);
            $table->boolean('pagamentos')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('contas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome');
            $table->string('banco', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $empresaId = (int) Conta::query()->create(['nome' => 'Acop Files'])->id;

        $banco = BankAccount::query()->create([
            'company_id' => $empresaId, 'company_name' => 'Acop Files',
            'bank_name' => 'Banco Itaú', 'bank_code' => '341',
            'agency' => '6260', 'number' => '13377-9',
            'active' => true, 'is_default' => true,
        ]);

        $this->operador = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);

        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operador->id],
            'reconciliation.manage_user_ids' => [$this->operador->id],
            'reconciliation.export_user_ids' => [$this->operador->id],
            'gestao.legacy_ui' => false,
        ]);

        $this->statement = PeriodStatement::query()->create([
            'account_id' => $empresaId,
            'account_name' => 'Acop Files',
            'account_bank' => null,
            'bank_account_id' => $banco->id,
            'period_start' => '2026-08-03',
            'period_end' => '2026-08-03',
            'opening_balance_cents' => 16290371,
            'closing_balance_cents' => 16398371,
            'total_in_cents' => 108000,
            'total_out_cents' => 0,
            'line_count' => 2,
            'generated_by' => $this->operador->id,
            'generated_at' => now(),
        ]);

        PeriodStatementLine::query()->create([
            'period_statement_id' => $this->statement->id,
            'line_number' => 1,
            'section' => PeriodStatementSection::Ledger,
            'movement_date' => '2026-08-03',
            'document_number' => 'NF.337',
            'origin_id' => '90317',
            'history' => 'V.01/08 Fundação Municipal de Saúde de Rio Claro',
            'amount_in_cents' => 108000,
            'amount_out_cents' => null,
            'running_balance_cents' => 16398371,
        ]);
        PeriodStatementLine::query()->create([
            'period_statement_id' => $this->statement->id,
            'line_number' => 2,
            'section' => PeriodStatementSection::Pending,
            'movement_date' => '2026-08-03',
            'due_date' => '2026-09-02',
            'document_number' => 'NF.388',
            'origin_id' => null,
            'history' => 'V.01/09 Sirio Libanes',
            'amount_in_cents' => 15449992,
            'amount_out_cents' => null,
            'running_balance_cents' => 0,
        ]);
    }

    /** @return array{sheet:string,styles:string,workbook:string} */
    private function abrir(string $binario): array
    {
        $caminho = tempnam(sys_get_temp_dir(), 'tst');
        file_put_contents($caminho, $binario);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($caminho) === true, 'O arquivo gerado não é um zip válido.');

        $partes = [
            'sheet' => $zip->getFromName('xl/worksheets/sheet1.xml'),
            'styles' => $zip->getFromName('xl/styles.xml'),
            'workbook' => $zip->getFromName('xl/workbook.xml'),
        ];
        $zip->close();
        @unlink($caminho);

        foreach ($partes as $nome => $conteudo) {
            $this->assertIsString($conteudo, "Parte ausente no xlsx: {$nome}");
        }

        return $partes;
    }

    /**
     * O pacote precisa ser consistente consigo mesmo, e não só "abrir".
     *
     * A primeira versão referenciava `theme="1"`/`theme="0"` em styles.xml sem
     * embarcar `xl/theme/theme1.xml`. O zip ficava íntegro e o openpyxl abria
     * sem reclamar — mas o **Excel recusa** o arquivo como corrompido, e foi
     * assim que o defeito chegou em produção sem nenhum teste acusar. O que
     * este teste cobra é o que o Excel cobra: toda parte referenciada existe,
     * toda parte existente está declarada em [Content_Types].
     */
    public function test_o_pacote_declara_tudo_que_referencia(): void
    {
        $caminho = tempnam(sys_get_temp_dir(), 'tst');
        file_put_contents($caminho, app(ConciliacaoItauXlsx::class)->gerar($this->statement));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($caminho) === true);

        $partes = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $partes[] = $zip->getNameIndex($i);
        }

        $tipos = (string) $zip->getFromName('[Content_Types].xml');
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $relsLivro = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        $zip->close();
        @unlink($caminho);

        // Cor de tema exige a parte do tema.
        if (str_contains($styles, 'theme=')) {
            $this->assertContains('xl/theme/theme1.xml', $partes,
                'styles.xml usa cor de tema, então o tema precisa estar no pacote.');
        }

        // Todo alvo declarado nos rels do workbook existe de fato.
        preg_match_all('/Target="([^"]+)"/', $relsLivro, $alvos);
        foreach ($alvos[1] as $alvo) {
            $this->assertContains('xl/'.ltrim($alvo, '/'), $partes,
                "O workbook aponta para '{$alvo}', que não está no pacote.");
        }

        // Toda parte .xml precisa de Default ou Override em [Content_Types].
        foreach ($partes as $parte) {
            if (! str_ends_with($parte, '.xml') || str_contains($parte, '_rels/')) {
                continue;
            }
            if ($parte === '[Content_Types].xml') {
                continue;
            }
            $this->assertStringContainsString('/'.$parte, $tipos,
                "A parte '{$parte}' não está declarada em [Content_Types].xml.");
        }
    }

    public function test_as_formulas_pedem_recalculo_na_abertura(): void
    {
        $partes = $this->abrir(app(ConciliacaoItauXlsx::class)->gerar($this->statement));

        // As fórmulas pedem recálculo para refletir qualquer edição manual.
        // Elas também carregam valor em cache, coberto pelo teste do saldo,
        // para o Excel nunca precisar abrir a coluna vazia.
        $this->assertStringContainsString('fullCalcOnLoad="1"', $partes['workbook']);
    }

    public function test_o_cabecalho_reproduz_as_duas_linhas_da_planilha(): void
    {
        $partes = $this->abrir(app(ConciliacaoItauXlsx::class)->gerar($this->statement));

        // Linha 1 a empresa, linha 2 a conta bancária — as mesmas duas linhas
        // mescladas de A até G que o arquivo do Itaú tem.
        $this->assertStringContainsString('Acop Files', $partes['sheet']);
        $this->assertStringContainsString('Banco Itaú - Agência 6260 - C/C 13377-9', $partes['sheet']);
        $this->assertStringContainsString('<mergeCell ref="A1:G1"/>', $partes['sheet']);
        $this->assertStringContainsString('<mergeCell ref="A2:G2"/>', $partes['sheet']);

        // Linha 3: os títulos das colunas, na ordem do original.
        foreach (['Data', 'Nº.Doc.', 'ID', 'Histórico', 'Entrada', 'Saída', 'Saldo Final'] as $titulo) {
            $this->assertStringContainsString($titulo, $partes['sheet']);
        }

        // Linha 4: o saldo de abertura é datado do dia ANTERIOR ao período.
        $this->assertStringContainsString('Saldo em 02/08/2026', $partes['sheet']);
        $this->assertStringContainsString('<c r="G4" s="6"><v>162903.71</v></c>', $partes['sheet']);
    }

    public function test_a_linha_1_traz_a_razao_social_completa_como_no_original(): void
    {
        // O sistema conhece a conta como "Acop Files" (account_name), mas a
        // planilha original tem a razão social por extenso. Lida diretamente
        // de K:\GERAL\TI\Conciliação Itaú Acop Files.xlsx.
        $partes = $this->abrir(app(ConciliacaoItauXlsx::class)->gerar($this->statement));

        $this->assertStringContainsString(
            'Acop Files Organização e Guarda de Documentos Ltda',
            $partes['sheet'],
        );
    }

    /**
     * As linhas mescladas precisam trazer TODAS as células de A a G.
     *
     * A primeira versão perdia a B1 e a B2: montar a linha com o operador `+`
     * de arrays faz o PHP manter a chave 0 do lado esquerdo e descartar a do
     * direito, em silêncio. O Excel abre assim mesmo, mas a célula fica sem a
     * borda do cabeçalho e a diferença aparece na tela.
     */
    public function test_as_linhas_mescladas_trazem_todas_as_celulas_de_a_ate_g(): void
    {
        $partes = $this->abrir(app(ConciliacaoItauXlsx::class)->gerar($this->statement));

        foreach (['B', 'C', 'D', 'E', 'F', 'G'] as $coluna) {
            foreach ([1, 2] as $linha) {
                $this->assertStringContainsString(
                    sprintf('<c r="%s%d" s="1"/>', $coluna, $linha),
                    $partes['sheet'],
                    "A célula {$coluna}{$linha} do cabeçalho mesclado sumiu.",
                );
            }
        }
    }

    public function test_o_saldo_corrido_sai_como_formula_e_nao_valor_congelado(): void
    {
        $partes = $this->abrir(app(ConciliacaoItauXlsx::class)->gerar($this->statement));

        // É o que o original faz, e é o que permite à equipe editar uma linha
        // no Excel e ver o saldo se refazer sozinho. Estilo 13 (não 6) porque
        // esta é a única movimentação do dia — logo também é a última.
        $this->assertStringContainsString(
            '<c r="G5" s="13"><f>G4+E5-F5</f><v>163983.71</v></c>',
            $partes['sheet'],
        );
    }

    public function test_cada_movimento_traz_o_saldo_calculado_e_o_ultimo_e_o_saldo_final(): void
    {
        PeriodStatementLine::query()
            ->where('period_statement_id', $this->statement->id)
            ->where('section', PeriodStatementSection::Pending)
            ->update(['line_number' => 3]);

        PeriodStatementLine::query()->create([
            'period_statement_id' => $this->statement->id,
            'line_number' => 2,
            'section' => PeriodStatementSection::Ledger,
            'movement_date' => '2026-08-03',
            'document_number' => 'PG.102',
            'origin_id' => '90318',
            'history' => 'Pagamento de fornecedor',
            'amount_in_cents' => null,
            'amount_out_cents' => 50000,
            'running_balance_cents' => 16348371,
        ]);

        $partes = $this->abrir(app(ConciliacaoItauXlsx::class)->gerar($this->statement));

        // A entrada soma ao saldo de abertura e a saída seguinte subtrai do
        // saldo anterior. O cache mostra os valores assim que o Excel abre;
        // a fórmula permanece para recalcular se alguém editar a planilha.
        // G5 continua estilo 6: há um segundo movimento no mesmo dia (G6),
        // então G5 não é mais o último do dia. G6 é o último e sai verde (13).
        $this->assertStringContainsString(
            '<c r="G5" s="6"><f>G4+E5-F5</f><v>163983.71</v></c>',
            $partes['sheet'],
        );
        $this->assertStringContainsString(
            '<c r="G6" s="13"><f>G5+E6-F6</f><v>163483.71</v></c>',
            $partes['sheet'],
        );
    }

    /**
     * Pedido do usuário em 2026-08-27: destacar em verde o saldo (coluna G)
     * do ÚLTIMO movimento de cada dia — não existe no arquivo original.
     */
    public function test_o_saldo_do_ultimo_movimento_de_cada_dia_fica_verde(): void
    {
        $statement = PeriodStatement::query()->create([
            'account_id' => $this->statement->account_id,
            'account_name' => 'Acop Files',
            'account_bank' => null,
            'bank_account_id' => $this->statement->bank_account_id,
            'period_start' => '2026-08-03',
            'period_end' => '2026-08-04',
            'opening_balance_cents' => 100000,
            'closing_balance_cents' => 100000,
            'total_in_cents' => 0,
            'total_out_cents' => 0,
            'line_count' => 3,
            'generated_by' => $this->operador->id,
            'generated_at' => now(),
        ]);

        // Dois movimentos em 03/08 (só o segundo é o último do dia) e um em
        // 04/08 (é o único, logo também é o último).
        PeriodStatementLine::query()->create([
            'period_statement_id' => $statement->id, 'line_number' => 1,
            'section' => PeriodStatementSection::Ledger, 'movement_date' => '2026-08-03',
            'document_number' => 'A', 'origin_id' => '1', 'history' => 'Primeiro do dia 3',
            'amount_in_cents' => 1000, 'amount_out_cents' => null, 'running_balance_cents' => 101000,
        ]);
        PeriodStatementLine::query()->create([
            'period_statement_id' => $statement->id, 'line_number' => 2,
            'section' => PeriodStatementSection::Ledger, 'movement_date' => '2026-08-03',
            'document_number' => 'B', 'origin_id' => '2', 'history' => 'Último do dia 3',
            'amount_in_cents' => 1000, 'amount_out_cents' => null, 'running_balance_cents' => 102000,
        ]);
        PeriodStatementLine::query()->create([
            'period_statement_id' => $statement->id, 'line_number' => 3,
            'section' => PeriodStatementSection::Ledger, 'movement_date' => '2026-08-04',
            'document_number' => 'C', 'origin_id' => '3', 'history' => 'Único do dia 4',
            'amount_in_cents' => 1000, 'amount_out_cents' => null, 'running_balance_cents' => 103000,
        ]);

        $partes = $this->abrir(app(ConciliacaoItauXlsx::class)->gerar($statement));

        $this->assertStringContainsString('<c r="G5" s="6"><f>G4+E5-F5</f><v>1010.00</v></c>', $partes['sheet']);
        $this->assertStringContainsString('<c r="G6" s="13"><f>G5+E6-F6</f><v>1020.00</v></c>', $partes['sheet']);
        $this->assertStringContainsString('<c r="G7" s="13"><f>G6+E7-F7</f><v>1030.00</v></c>', $partes['sheet']);
    }

    /**
     * Pedido do usuário em 2026-08-27: a planilha baixada não traz mais o
     * bloco de "em aberto" — títulos que ainda não caíram no banco. O
     * original tem esse bloco (sem data, sem saldo); aqui ele simplesmente
     * não aparece, e a linha do movimento vai direto para o espaço em branco
     * do fim.
     */
    public function test_titulos_em_aberto_nao_entram_na_planilha(): void
    {
        $partes = $this->abrir(app(ConciliacaoItauXlsx::class)->gerar($this->statement));

        $this->assertStringNotContainsString('V.01/09 Sirio Libanes', $partes['sheet']);

        // Só o movimento (linha 5) e, na sequência, direto as linhas em
        // branco do fim — sem gap nem linha extra para o pendente.
        $this->assertStringContainsString('<c r="A6" s="3"/>', $partes['sheet']);
    }

    public function test_reproduz_o_formato_de_moeda_e_as_larguras_do_arquivo_original(): void
    {
        $partes = $this->abrir(app(ConciliacaoItauXlsx::class)->gerar($this->statement));

        // Formato de moeda lido do styles.xml do arquivo real (numFmtId 164).
        $this->assertStringContainsString(
            '_(&quot;R$ &quot;* #,##0.00_);_(&quot;R$ &quot;* \(#,##0.00\);',
            $partes['styles'],
        );

        // Fontes: Arial 12 no cabeçalho, Arial 10 negrito nos títulos das
        // colunas. A Saída é preta (não vermelha como no original — pedido do
        // usuário em 2026-08-27), e o saldo do último movimento de cada dia
        // ganhou fundo verde, que não existe no arquivo original.
        $this->assertStringContainsString('<sz val="12"/><color theme="1"/><name val="Arial"/>', $partes['styles']);
        $this->assertStringContainsString('<b/><sz val="10"/><color theme="1"/><name val="Arial"/>', $partes['styles']);
        $this->assertStringNotContainsString('<color rgb="FFFF0000"/>', $partes['styles']);
        $this->assertStringContainsString('<fgColor rgb="FFC6E0B4"/>', $partes['styles']);

        // Larguras medidas coluna a coluna no arquivo do Itaú.
        foreach (['14.140625', '9.42578125', '11.85546875', '75.5703125', '15.85546875', '14.28515625'] as $largura) {
            $this->assertStringContainsString('width="'.$largura.'"', $partes['sheet']);
        }
    }

    public function test_a_aba_usa_a_convencao_de_nome_da_planilha(): void
    {
        $partes = $this->abrir(app(ConciliacaoItauXlsx::class)->gerar($this->statement));

        $this->assertStringContainsString('name="Agosto-2026"', $partes['workbook']);
    }

    public function test_a_data_sai_no_serial_do_excel(): void
    {
        $partes = $this->abrir(app(ConciliacaoItauXlsx::class)->gerar($this->statement));

        // 03/08/2026 = 46237 na contagem do Excel (época 1899-12-30).
        $this->assertStringContainsString('<c r="A5" s="3"><v>46237</v></c>', $partes['sheet']);
    }

    public function test_a_rota_entrega_o_arquivo_com_o_nome_e_o_tipo_certos(): void
    {
        $resposta = $this->actingAs($this->operador)
            ->get("/conciliacao/{$this->statement->id}/planilha");

        $resposta->assertOk();
        $resposta->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringContainsString(
            'Conciliacao-Acop-Files-2026-08-03.xlsx',
            (string) $resposta->headers->get('content-disposition'),
        );

        // E o que sai pela rota é um xlsx de verdade, não uma página de erro.
        $partes = $this->abrir($resposta->streamedContent());
        $this->assertStringContainsString('Acop Files', $partes['sheet']);
    }

    public function test_baixar_exige_a_permissao_de_exportar(): void
    {
        // Ver a conciliação não basta: baixar tira o dado do sistema.
        config(['reconciliation.export_user_ids' => [], 'reconciliation.close_user_ids' => []]);

        $this->actingAs($this->operador)
            ->get("/conciliacao/{$this->statement->id}/planilha")
            ->assertForbidden();
    }
}
