<?php

namespace App\Console\Commands;

use App\Application\Financial\ManualMovementService;
use App\Models\BankAccount;
use App\Models\Conta;
use App\Models\ManualMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Importa das planilhas de conciliação as linhas que NÃO existem nas origens.
 *
 * Cada aba das planilhas do Itaú tem uma coluna ID que liga a linha ao
 * lançamento de origem: número para Contas a Pagar, "Com."/"Com.R" para Contas
 * a Receber. **ID vazio significa que aquilo foi digitado direto na planilha** —
 * tarifa, rendimento de aplicação, transferência entre contas do grupo, e
 * recebimentos que ninguém chegou a cadastrar. É dinheiro que entrou ou saiu da
 * conta e não tem casa em sistema nenhum.
 *
 * Só essas linhas entram. As linhas COM ID já chegam pela sincronização, e
 * importá-las aqui as contaria duas vezes.
 *
 * O comando é IDEMPOTENTE: cada linha carrega um `correlation_id` derivado do
 * seu conteúdo e da posição na aba, então rodar de novo não duplica nada.
 *
 * Antes de gravar, cada linha é confrontada com as liquidações já existentes na
 * mesma conta, data e valor. Bater não impede o import, mas é reportado: pode
 * ser coincidência legítima (duas tarifas iguais no mesmo dia) ou pode ser o
 * mesmo dinheiro chegando por dois caminhos, e essa diferença é de quem conhece
 * a operação, não do script.
 */
class ImportSpreadsheetMovements extends Command
{
    protected $signature = 'gestao:importar-movimentos-planilha
        {arquivo : JSON gerado a partir das planilhas de conciliação}
        {--dry-run : Só relata o que faria, sem gravar nada}
        {--empresa= : Limita a uma empresa (nome como está em `contas`)}
        {--ator= : Id do usuário a registrar como autor}';

    protected $description = 'Importa como movimentos manuais as linhas das planilhas de conciliação que não vêm das origens';

    public function __construct(private readonly ManualMovementService $movimentos)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $arquivo = (string) $this->argument('arquivo');

        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        $registros = json_decode((string) file_get_contents($arquivo), true);

        if (! is_array($registros)) {
            $this->error('JSON inválido.');

            return self::FAILURE;
        }

        $seco = (bool) $this->option('dry-run');
        $filtro = (string) ($this->option('empresa') ?? '');
        $ator = $this->option('ator') !== null ? (int) $this->option('ator') : null;

        $this->info($seco
            ? 'MODO SECO — nada será gravado.'
            : 'GRAVANDO movimentos manuais.');
        $this->newLine();

        $empresas = [];
        $contagem = ['novos' => 0, 'repetidos' => 0, 'sem_empresa' => 0, 'suspeitos' => 0, 'erros' => 0];
        $suspeitos = [];

        foreach ($registros as $r) {
            $nome = (string) ($r['empresa'] ?? '');

            if ($filtro !== '' && mb_strtolower($nome) !== mb_strtolower($filtro)) {
                continue;
            }

            if (! array_key_exists($nome, $empresas)) {
                $empresas[$nome] = $this->resolverEmpresa($nome);
            }

            $empresa = $empresas[$nome];

            if ($empresa === null) {
                $contagem['sem_empresa']++;

                continue;
            }

            [$conta, $banco] = $empresa;

            // Idempotência: a mesma linha da mesma aba nunca entra duas vezes.
            // `withTrashed()` de propósito — um movimento importado e depois
            // excluído foi uma decisão de alguém, e reimportá-lo a desfaria.
            if (ManualMovement::withTrashed()->where('import_key', $r['import_key'])->exists()) {
                $contagem['repetidos']++;

                continue;
            }

            if ($this->pareceLiquidacaoExistente($conta->id, $r)) {
                $contagem['suspeitos']++;
                $suspeitos[] = sprintf(
                    '%s %s %s R$ %s — %s',
                    $nome,
                    $r['movement_date'],
                    $r['direction'],
                    $r['amount'],
                    mb_substr((string) $r['history'], 0, 60),
                );
            }

            $contagem['novos']++;

            if ($seco) {
                continue;
            }

            try {
                $this->movimentos->create([
                    'account_id' => $conta->id,
                    'bank_account_id' => $banco?->id,
                    'document_number' => $r['document_number'] ?? null,
                    'movement_date' => $r['movement_date'],
                    'direction' => $r['direction'],
                    'amount' => $r['amount'],
                    'history' => $r['history'],
                    'notes' => sprintf('Importado da planilha de conciliação (%s, linha %s).', $r['aba'], $r['linha_planilha']),
                    'import_key' => $r['import_key'],
                ], $ator);
            } catch (Throwable $e) {
                $contagem['erros']++;
                $this->warn(sprintf('  falhou %s %s: %s', $nome, $r['movement_date'], $e->getMessage()));
            }
        }

        $this->table(['', 'Linhas'], [
            ['A importar', $contagem['novos']],
            ['Já importadas antes', $contagem['repetidos']],
            ['Empresa não cadastrada', $contagem['sem_empresa']],
            ['Batem com liquidação existente', $contagem['suspeitos']],
            ['Erros', $contagem['erros']],
        ]);

        if ($suspeitos !== []) {
            $this->newLine();
            $this->warn('Linhas que coincidem com uma liquidação já existente (conta, data e valor).');
            $this->warn('Pode ser coincidência legítima ou dinheiro contado duas vezes — confira:');
            foreach (array_slice($suspeitos, 0, 40) as $s) {
                $this->line('  '.$s);
            }
            if (count($suspeitos) > 40) {
                $this->line(sprintf('  ... e mais %d.', count($suspeitos) - 40));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Empresa (`contas`) e a conta bancária padrão dela.
     *
     * O nome tem de bater exatamente com o cadastro — associar por semelhança
     * jogaria dinheiro na empresa errada, que é pior do que não importar.
     *
     * @return array{0: Conta, 1: BankAccount|null}|null
     */
    private function resolverEmpresa(string $nome): ?array
    {
        $conta = Conta::query()->whereRaw('LOWER(nome) = ?', [mb_strtolower(trim($nome))])->first();

        if ($conta === null) {
            $this->warn("Empresa \"{$nome}\" não encontrada em `contas` — linhas dela serão puladas.");

            return null;
        }

        $banco = BankAccount::padraoDaEmpresa((int) $conta->id);

        if ($banco === null) {
            $this->warn("Empresa \"{$nome}\" não tem conta bancária padrão — os movimentos entrarão sem banco.");
        }

        return [$conta, $banco];
    }

    /**
     * Já existe uma liquidação confirmada nesta conta, nesta data e neste valor?
     *
     * Não é prova de duplicidade — duas saídas de R$ 35 no mesmo dia acontecem —
     * mas é o sinal barato de que a mesma quantia pode estar chegando pelos dois
     * caminhos. Serve para reportar, nunca para decidir sozinho.
     */
    private function pareceLiquidacaoExistente(int $contaId, array $r): bool
    {
        // Sem SQL cru: as tabelas levam prefixo (`avt_`) em produção, e um nome
        // escrito à mão aqui só quebraria lá. O valor é comparado como decimal
        // contra a string normalizada ("119.40"), que é exatamente o formato em
        // que a coluna decimal(15,2) guarda.
        return DB::table('title_settlements')
            ->join('financial_titles', 'financial_titles.id', '=', 'title_settlements.financial_title_id')
            ->where('title_settlements.status', 'CONFIRMED')
            ->where('financial_titles.account_id', $contaId)
            ->whereNull('financial_titles.deleted_at')
            ->whereDate('title_settlements.settlement_date', $r['movement_date'])
            ->where('title_settlements.amount', $r['amount'])
            ->exists();
    }
}
