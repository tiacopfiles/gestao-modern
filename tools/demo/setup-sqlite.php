<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expectedDatabase = realpath(base_path('database/database.sqlite'));
$configuredDatabase = realpath((string) config('database.connections.sqlite.database'));

if (app()->environment() !== 'local') {
    throw new RuntimeException('ABORT: o setup de demonstração exige APP_ENV=local.');
}
if (config('database.default') !== 'sqlite') {
    throw new RuntimeException('ABORT: o setup de demonstração aceita somente SQLite.');
}
if ($expectedDatabase === false || $configuredDatabase === false || $expectedDatabase !== $configuredDatabase) {
    throw new RuntimeException('ABORT: o banco deve ser exatamente database/database.sqlite deste projeto.');
}
if (DB::getTablePrefix() !== '') {
    throw new RuntimeException('ABORT: o setup local não aceita prefixo de tabelas.');
}

foreach (['avt_lancamentos', 'avt_recebimentos', 'avt_movimentos', 'avt_conciliacoes'] as $protected) {
    if (Schema::hasTable($protected)) {
        throw new RuntimeException("ABORT: tabela protegida encontrada: {$protected}.");
    }
}

$migrationExit = Artisan::call('migrate', ['--force' => true]);
if ($migrationExit !== 0) {
    throw new RuntimeException('Falha ao aplicar migrations modernas no SQLite local: '.Artisan::output());
}

addDemoUserColumns();
createSimpleRegistry('contas', ['nome', 'nome_detalhado', 'dados_completos']);
createSimpleRegistry('tipos', ['nome']);
createSimpleRegistry('situacoes', ['nome']);
createSimpleRegistry('categorias', ['nome']);
createSimpleRegistry('centrocusto', ['nome']);
createPartyTable('clientes', true);
createPartyTable('fornecedores', false);
createFinancialTable('lancamentos', false);
createFinancialTable('recebimentos', true);
createMovementsTable();
createReconciliationsTable();
createLegacyAuditTable();

$now = now();
$today = now()->startOfDay();

DB::transaction(function () use ($now, $today): void {
    DB::table('users')->updateOrInsert(
        ['username' => 'demo@acop.local'],
        [
            'name' => 'Usuário Demonstração', 'nome' => 'Usuário Demonstração',
            'email' => 'demo@acop.local', 'empresa' => 'Ambiente local sintético',
            'comercial' => 1, 'pagamentos' => 1,
            'password' => Hash::make('Demo@Acop2026'), 'deleted_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ],
    );

    seedRegistry('contas', [
        ['id' => 900001, 'nome' => 'Conta Operacional — Demonstração', 'nome_detalhado' => 'Banco sintético · Ag. 0001', 'dados_completos' => 'Dados exclusivamente fictícios'],
        ['id' => 900002, 'nome' => 'Conta Reserva — Demonstração', 'nome_detalhado' => 'Banco sintético · Ag. 0002', 'dados_completos' => 'Dados exclusivamente fictícios'],
    ], $now);
    seedRegistry('tipos', [['id' => 900001, 'nome' => 'Serviço'], ['id' => 900002, 'nome' => 'Operacional']], $now);
    seedRegistry('situacoes', [['id' => 1, 'nome' => 'Pendente'], ['id' => 2, 'nome' => 'Programado'], ['id' => 4, 'nome' => 'Liquidado']], $now);
    seedRegistry('categorias', [['id' => 900001, 'nome' => 'Operação'], ['id' => 900002, 'nome' => 'Serviços']], $now);
    seedRegistry('centrocusto', [['id' => 900001, 'nome' => 'Administrativo'], ['id' => 900002, 'nome' => 'Comercial']], $now);

    seedParty('clientes', 900001, 'Cliente Horizonte — Sintético', $now);
    seedParty('clientes', 900002, 'Cliente Aurora — Sintético', $now);
    seedParty('fornecedores', 900001, 'Fornecedor Delta — Sintético', $now);
    seedParty('fornecedores', 900002, 'Fornecedor Prisma — Sintético', $now);

    $common = [
        'tipo' => '900001', 'data_emissao' => $today->copy()->subDays(5)->toDateString(),
        'categoria' => '900001', 'conta' => '900001', 'centrocusto' => '900001',
        'situacao' => '1', 'pc' => '', 'numero_pc' => '', 'competencia' => $today->format('m/Y'),
        'obs' => 'Registro exclusivamente sintético para visualização local.',
        'acrescimo' => '0.00', 'desconto' => '0.00', 'tipo_lancamento' => 'demo',
        'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now,
    ];
    DB::table('lancamentos')->updateOrInsert(['id' => 900001], $common + [
        'fornecedor' => '900001', 'numero_doc' => 'DEMO-PAG-001',
        'data_vencimento' => $today->copy()->addDays(3)->toDateString(),
        'data_pagamento' => null, 'valor' => '2450.00', 'valor_total' => '2450.00',
    ]);
    DB::table('lancamentos')->updateOrInsert(['id' => 900002], $common + [
        'fornecedor' => '900002', 'numero_doc' => 'DEMO-PAG-002',
        'data_vencimento' => $today->copy()->subDays(2)->toDateString(),
        'data_pagamento' => null, 'valor' => '780.50', 'valor_total' => '780.50',
    ]);
    DB::table('recebimentos')->updateOrInsert(['id' => 900001], $common + [
        'cliente' => '900001', 'fornecedor' => '', 'numero_doc' => 'DEMO-REC-001',
        'data_vencimento' => $today->copy()->addDays(2)->toDateString(),
        'data_pagamento' => null, 'valor' => '5200.00', 'valor_total' => '5200.00',
    ]);
    DB::table('recebimentos')->updateOrInsert(['id' => 900002], $common + [
        'cliente' => '900002', 'fornecedor' => '', 'numero_doc' => 'DEMO-REC-002',
        'data_vencimento' => $today->copy()->addDays(6)->toDateString(),
        'data_pagamento' => null, 'valor' => '1875.90', 'valor_total' => '1875.90',
    ]);

    foreach ([
        [900001, 'entrada', 'Recebimento demonstrativo', '1250.00', 0],
        [900002, 'saida', 'Pagamento demonstrativo', '420.30', 1],
        [900003, 'entrada', 'Ajuste sintético de saldo', '300.00', 2],
    ] as [$id, $operation, $description, $amount, $daysAgo]) {
        DB::table('movimentos')->updateOrInsert(['id' => $id], [
            'id_conta' => '900001', 'data_referencia' => $today->copy()->subDays($daysAgo)->toDateString(),
            'descricao' => $description, 'operacao' => $operation, 'valor' => $amount,
            'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
});

echo "DEMO_SQLITE_READY\n";
echo "URL: http://127.0.0.1:8000/login\n";
echo "Usuário: demo@acop.local\n";
echo "Senha: Demo@Acop2026\n";
echo "Dados: 100% sintéticos e locais\n";

function addDemoUserColumns(): void
{
    $columns = [
        'nome' => fn (Blueprint $table) => $table->string('nome')->nullable(),
        'username' => fn (Blueprint $table) => $table->string('username')->nullable()->index(),
        'telefone' => fn (Blueprint $table) => $table->string('telefone', 40)->nullable(),
        'celular' => fn (Blueprint $table) => $table->string('celular', 40)->nullable(),
        'empresa' => fn (Blueprint $table) => $table->string('empresa')->nullable(),
        'comercial' => fn (Blueprint $table) => $table->boolean('comercial')->default(false),
        'pagamentos' => fn (Blueprint $table) => $table->boolean('pagamentos')->default(false),
        'senha' => fn (Blueprint $table) => $table->string('senha')->nullable(),
        'deleted_at' => fn (Blueprint $table) => $table->softDeletes(),
    ];

    foreach ($columns as $name => $definition) {
        if (! Schema::hasColumn('users', $name)) {
            Schema::table('users', $definition);
        }
    }
}

/** @param list<string> $columns */
function createSimpleRegistry(string $name, array $columns): void
{
    if (Schema::hasTable($name)) {
        return;
    }
    Schema::create($name, function (Blueprint $table) use ($columns): void {
        $table->id();
        foreach ($columns as $column) {
            $table->string($column)->default('');
        }
        $table->timestamps();
        $table->softDeletes();
    });
}

function createPartyTable(string $name, bool $withResponsible): void
{
    if (Schema::hasTable($name)) {
        return;
    }
    Schema::create($name, function (Blueprint $table) use ($withResponsible): void {
        $table->id();
        $table->string('nome_fantasia');
        $table->string('razao_social');
        if ($withResponsible) {
            $table->string('responsavel')->default('');
            $table->string('cpf')->default('');
        }
        foreach (['cnpj', 'cep', 'estado', 'cidade', 'endereco', 'numero', 'complemento', 'bairro', 'email', 'telefone', 'celular'] as $column) {
            $table->string($column)->default('');
        }
        $table->timestamps();
        $table->softDeletes();
    });
}

function createFinancialTable(string $name, bool $receivable): void
{
    if (Schema::hasTable($name)) {
        return;
    }
    Schema::create($name, function (Blueprint $table) use ($receivable): void {
        $table->id();
        if ($receivable) {
            $table->string('cliente')->default('');
        }
        $table->string('fornecedor')->default('');
        foreach (['numero_doc', 'tipo', 'categoria', 'conta', 'centrocusto', 'situacao', 'pc', 'numero_pc', 'competencia', 'obs', 'tipo_lancamento'] as $column) {
            $table->string($column)->default('');
        }
        $table->date('data_emissao');
        $table->date('data_vencimento');
        $table->date('data_pagamento')->nullable();
        foreach (['valor', 'acrescimo', 'desconto', 'valor_total'] as $column) {
            $table->decimal($column, 15, 2)->default(0);
        }
        $table->timestamps();
        $table->softDeletes();
    });
}

function createMovementsTable(): void
{
    if (Schema::hasTable('movimentos')) {
        return;
    }
    Schema::create('movimentos', function (Blueprint $table): void {
        $table->id();
        $table->string('id_conta');
        $table->date('data_referencia');
        $table->string('descricao');
        $table->string('operacao', 20);
        $table->decimal('valor', 15, 2);
        $table->timestamps();
        $table->softDeletes();
    });
}

function createReconciliationsTable(): void
{
    if (Schema::hasTable('conciliacoes')) {
        return;
    }
    Schema::create('conciliacoes', function (Blueprint $table): void {
        $table->id();
        $table->string('id_conta');
        $table->date('data_inicial');
        $table->date('data_final');
        $table->string('mes', 2);
        $table->string('ano', 4);
        $table->string('status', 20)->default('ABERTA');
        $table->date('data_cadastro');
        $table->timestamps();
        $table->softDeletes();
    });
}

function createLegacyAuditTable(): void
{
    if (Schema::hasTable('logs')) {
        return;
    }
    Schema::create('logs', function (Blueprint $table): void {
        $table->increments('id_log');
        $table->string('nome_tabela');
        $table->string('registro');
        $table->string('tipo_alteracao');
        $table->string('id_usuario');
        $table->date('data');
        $table->timestamps();
        $table->softDeletes();
    });
}

/** @param list<array<string, int|string>> $rows */
function seedRegistry(string $table, array $rows, mixed $now): void
{
    foreach ($rows as $row) {
        DB::table($table)->updateOrInsert(['id' => $row['id']], $row + ['created_at' => $now, 'updated_at' => $now, 'deleted_at' => null]);
    }
}

function seedParty(string $table, int $id, string $name, mixed $now): void
{
    $data = [
        'nome_fantasia' => $name, 'razao_social' => $name.' Ltda.', 'cnpj' => '',
        'cep' => '', 'estado' => 'SP', 'cidade' => 'Cidade demonstração',
        'endereco' => 'Endereço sintético', 'numero' => 'S/N', 'complemento' => '',
        'bairro' => 'Centro', 'email' => '', 'telefone' => '', 'celular' => '',
        'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
    ];
    if ($table === 'clientes') {
        $data += ['responsavel' => 'Contato sintético', 'cpf' => ''];
    }
    DB::table($table)->updateOrInsert(['id' => $id], $data);
}
