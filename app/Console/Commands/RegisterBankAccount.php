<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\Conta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastra uma conta bancária e, quando houver evidência, a liga à empresa.
 *
 * O vínculo com a empresa só é gravado quando o nome informado casa com um
 * cadastro existente. Sem casar, a conta é criada com a empresa em branco: uma
 * conta sem dono declarado é um fato; uma conta ligada à empresa errada é um
 * erro que se propaga para todo relatório que usar esse vínculo.
 */
class RegisterBankAccount extends Command
{
    protected $signature = 'gestao:conta-bancaria
        {--banco= : Nome do banco (ex.: "Banco Itaú")}
        {--codigo= : Código do banco (ex.: 341)}
        {--agencia= : Agência}
        {--numero= : Número da conta}
        {--empresa= : Nome da empresa como cadastrada (opcional)}
        {--rotulo= : Como a conta aparece no relatório de origem}
        {--padrao : Marca como a conta padrão da empresa (a que recebe as liquidações vindas das origens)}
        {--listar : Apenas lista o que já está cadastrado}';

    protected $description = 'Cadastra uma conta bancária (banco/agência/conta) e a associa a uma empresa quando houver evidência';

    public function handle(): int
    {
        if (! Schema::hasTable('bank_accounts')) {
            $this->error('Tabela bank_accounts ausente. Rode as migrations primeiro.');

            return self::FAILURE;
        }

        if ($this->option('listar')) {
            return $this->listar();
        }

        foreach (['banco', 'agencia', 'numero'] as $obrigatorio) {
            if (blank($this->option($obrigatorio))) {
                $this->error("--{$obrigatorio} é obrigatório.");

                return self::FAILURE;
            }
        }

        $empresaNome = (string) $this->option('empresa');
        $empresa = null;

        if ($empresaNome !== '') {
            $empresa = Conta::query()->whereRaw('LOWER(nome) = ?', [mb_strtolower(trim($empresaNome))])->first();

            if ($empresa === null) {
                $this->warn("Empresa \"{$empresaNome}\" não encontrada no cadastro.");
                $this->warn('A conta será criada SEM vínculo — associar por semelhança de nome seria chute.');
            }
        }

        $padrao = (bool) $this->option('padrao');

        if ($padrao && $empresa === null) {
            $this->error('--padrao exige uma --empresa confirmada: conta padrão "de ninguém" não significa nada.');

            return self::FAILURE;
        }

        $conta = BankAccount::query()->updateOrCreate(
            [
                'bank_code' => (string) $this->option('codigo'),
                'agency' => (string) $this->option('agencia'),
                'number' => (string) $this->option('numero'),
            ],
            [
                'bank_name' => (string) $this->option('banco'),
                'company_id' => $empresa?->id,
                'company_name' => $empresa?->nome ?? ($empresaNome !== '' ? $empresaNome.' (não confirmado)' : null),
                'label' => (string) ($this->option('rotulo') ?: ''),
                'active' => true,
            ],
        );

        if ($padrao) {
            // Uma empresa tem UMA conta padrão. Marcar a nova sem desmarcar a
            // antiga deixaria duas, e `padraoDaEmpresa()` passaria a devolver
            // qualquer uma das duas conforme a ordem do banco — o tipo de
            // ambiguidade que só aparece meses depois, num relatório errado.
            BankAccount::query()
                ->where('company_id', $empresa->id)
                ->whereKeyNot($conta->id)
                ->update(['is_default' => false]);

            $conta->update(['is_default' => true]);
        }

        $this->info(sprintf(
            'Conta %s — empresa: %s%s',
            $conta->fullLabel(),
            $empresa?->nome ?? 'NÃO CONFIRMADA',
            $conta->fresh()->is_default ? ' — PADRÃO da empresa' : '',
        ));

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $contas = BankAccount::query()->orderBy('bank_name')->orderBy('agency')->get();

        if ($contas->isEmpty()) {
            $this->warn('Nenhuma conta bancária cadastrada.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Banco', 'Agência', 'Conta', 'Empresa', 'Vínculo', 'Padrão'],
            $contas->map(fn (BankAccount $c): array => [
                $c->id,
                $c->bank_name,
                $c->agency,
                $c->number,
                $c->company_name ?? '—',
                $c->company_id === null ? 'NAO CONFIRMADO' : 'confirmado',
                $c->is_default ? 'SIM' : '',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
