<?php

namespace App\Application\Integration;

use App\Integration\OriginReader;
use App\Support\SchemaCompat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Traz os cadastros das origens legadas para o Gestão: categorias, tipos,
 * situações, centros de custo, fornecedores e clientes.
 *
 * Direção única, ORIGENS → GESTÃO, e a origem é aberta pelo OriginReader, que
 * recusa qualquer instrução de escrita.
 *
 * Duas armadilhas de nome, ambas confirmadas nos dados:
 *
 * 1. A tabela `fornecedor` existe nos DOIS bancos, mas só em `contas` ela é de
 *    fornecedores. Em `contasareceber` os registros têm CPF e nome de pessoa
 *    física: são os clientes que pagam aluguel. Importar os dois como
 *    "fornecedores" misturaria quem cobra com quem paga.
 * 2. `cnpj` não serve como identidade: aparece "001" repetido em fornecedores
 *    diferentes. A deduplicação é pelo nome, que é o que de fato distingue.
 */
class OriginRegistrySyncService
{
    /** Cadastros simples: uma coluna `nome`, união dos dois bancos. */
    private const SIMPLE = [
        'categoria' => 'categorias',
        'tipo' => 'tipos',
        'situacao' => 'situacoes',
        'centrodecusto' => 'centrocusto',
    ];

    /**
     * @return array<string, array{lidos: int, criados: int, atualizados: int, ignorados: int}>
     */
    public function sync(): array
    {
        $resultado = [];

        foreach (self::SIMPLE as $origem => $destino) {
            $resultado[$destino] = $this->syncSimple($origem, $destino);
        }

        $resultado['fornecedores'] = $this->syncParties('contas', 'fornecedores');
        $resultado['clientes'] = $this->syncParties('contasareceber', 'clientes');

        return $resultado;
    }

    private function reader(string $database): OriginReader
    {
        return new OriginReader(
            (string) env('ORIGIN_DB_HOST', '127.0.0.1'),
            (int) env('ORIGIN_DB_PORT', 3306),
            $database,
            (string) env('ORIGIN_DB_USER', 'ROOT'),
            (string) env('ORIGIN_DB_PASSWORD', ''),
        );
    }

    /**
     * @return array{lidos: int, criados: int, atualizados: int, ignorados: int}
     */
    private function syncSimple(string $originTable, string $targetTable): array
    {
        $stats = ['lidos' => 0, 'criados' => 0, 'atualizados' => 0, 'ignorados' => 0];

        if (! Schema::hasTable($targetTable)) {
            return $stats;
        }

        // Índice do que já existe, por nome normalizado: reimportar reconhece em
        // vez de duplicar, e nomes iguais nos dois bancos viram um só cadastro.
        $existentes = [];
        foreach (DB::table($targetTable)->get(['id', 'nome']) as $linha) {
            $existentes[$this->chave((string) $linha->nome)] = (int) $linha->id;
        }

        foreach (['contas', 'contasareceber'] as $database) {
            $linhas = $this->reader($database)->select("SELECT nome FROM {$originTable}");

            foreach ($linhas as $linha) {
                $stats['lidos']++;
                $nome = trim((string) ($linha['nome'] ?? ''));

                if ($nome === '') {
                    $stats['ignorados']++;

                    continue;
                }

                $chave = $this->chave($nome);

                if (isset($existentes[$chave])) {
                    $stats['ignorados']++;

                    continue;
                }

                $existentes[$chave] = (int) DB::table($targetTable)->insertGetId([
                    'nome' => $nome,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $stats['criados']++;
            }
        }

        return $stats;
    }

    /**
     * @return array{lidos: int, criados: int, atualizados: int, ignorados: int}
     */
    private function syncParties(string $database, string $targetTable): array
    {
        $stats = ['lidos' => 0, 'criados' => 0, 'atualizados' => 0, 'ignorados' => 0];

        if (! Schema::hasTable($targetTable)) {
            return $stats;
        }

        $temEmail = $this->reader($database)->select(
            "SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = '{$database}' AND table_name = 'fornecedor' AND column_name = 'email'"
        );
        $colunas = 'id, cnpj, nomefantasia, razaosocial, endereco, cidade, estado, bairro, cep, telefone1, telefone2';
        if ((int) ($temEmail[0]['n'] ?? 0) > 0) {
            $colunas .= ', email';
        }

        // Identidade pelo par (origem, id na origem). Deduplicar por nome funde
        // homonimos — "Diego Donizete da Cunha Silva" sao duas pessoas com CPFs
        // diferentes em contasareceber — e o documento tambem nao serve, porque
        // "001" aparece como CNPJ de fornecedores diferentes em contas.
        $temOrigem = SchemaCompat::hasColumn($targetTable, 'origem_id');

        $existentes = [];
        if ($temOrigem) {
            foreach (DB::table($targetTable)->whereNotNull('origem_id')->get(['id', 'origem_sistema', 'origem_id']) as $linha) {
                $existentes[$linha->origem_sistema.'#'.$linha->origem_id] = (int) $linha->id;
            }
        }

        $temCpf = SchemaCompat::hasColumn($targetTable, 'cpf');
        $temResponsavel = SchemaCompat::hasColumn($targetTable, 'responsavel');

        // As colunas aceitas sao descobertas UMA vez. Consultar o schema dentro
        // do laco custaria uma consulta por coluna e por linha — com 4.601
        // fornecedores isso passa de 60 mil idas ao banco por nada.
        $colunasAceitas = array_filter(
            ['nome_fantasia', 'razao_social', 'cnpj', 'cpf', 'responsavel', 'cep', 'estado',
                'cidade', 'endereco', 'numero', 'complemento', 'bairro', 'email',
                'telefone', 'celular', 'origem_sistema', 'origem_id', 'created_at', 'updated_at'],
            fn (string $coluna): bool => SchemaCompat::hasColumn($targetTable, $coluna),
        );
        $colunasAceitas = array_flip($colunasAceitas);

        foreach ($this->reader($database)->select("SELECT {$colunas} FROM fornecedor") as $linha) {
            $stats['lidos']++;

            $fantasia = trim((string) ($linha['nomefantasia'] ?? ''));
            $razao = trim((string) ($linha['razaosocial'] ?? ''));

            if ($fantasia === '' && $razao === '') {
                $stats['ignorados']++;

                continue;
            }

            $fantasiaGravada = Str::limit($fantasia !== '' ? $fantasia : $razao, 190, '');
            $razaoGravada = Str::limit($razao !== '' ? $razao : $fantasia, 190, '');

            $chave = $database.'#'.$linha['id'];

            if (isset($existentes[$chave])) {
                $stats['ignorados']++;

                continue;
            }

            $documento = preg_replace('/\D/', '', (string) ($linha['cnpj'] ?? '')) ?? '';

            $dados = [
                'nome_fantasia' => $fantasiaGravada,
                'razao_social' => $razaoGravada,
                // 11 dígitos é CPF, 14 é CNPJ. A origem guarda os dois na mesma
                // coluna `cnpj`, e em contasareceber a maioria é pessoa física.
                'cnpj' => $temCpf && strlen($documento) === 11 ? '' : (string) ($linha['cnpj'] ?? ''),
                'cep' => (string) ($linha['cep'] ?? ''),
                'estado' => (string) ($linha['estado'] ?? ''),
                'cidade' => (string) ($linha['cidade'] ?? ''),
                'endereco' => (string) ($linha['endereco'] ?? ''),
                'numero' => '',
                'complemento' => '',
                'bairro' => (string) ($linha['bairro'] ?? ''),
                'email' => (string) ($linha['email'] ?? ''),
                'telefone' => (string) ($linha['telefone1'] ?? ''),
                'celular' => (string) ($linha['telefone2'] ?? ''),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($temCpf) {
                $dados['cpf'] = strlen($documento) === 11 ? (string) ($linha['cnpj'] ?? '') : '';
            }
            if ($temResponsavel) {
                $dados['responsavel'] = '';
            }
            if ($temOrigem) {
                $dados['origem_sistema'] = $database;
                $dados['origem_id'] = (int) $linha['id'];
            }

            $dados = array_intersect_key($dados, $colunasAceitas);

            $existentes[$chave] = (int) DB::table($targetTable)->insertGetId($dados);
            $stats['criados']++;
        }

        return $stats;
    }

    /** Nomes iguais com acento, caixa ou espaço diferente são o mesmo cadastro. */
    private function chave(string $valor): string
    {
        return Str::of($valor)->lower()->ascii()->squish()->toString();
    }
}
