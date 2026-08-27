<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_systems', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->string('type', 40)->default('INTEGRATION');
            $table->boolean('active')->default(true);
            $table->longText('configuration')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('source_systems')->insert([
            ['code' => 'MANUAL', 'name' => 'Entrada manual', 'type' => 'INTERNAL', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'LEGACY_PAYABLE', 'name' => 'Contas a pagar legadas', 'type' => 'LEGACY', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'LEGACY_RECEIVABLE', 'name' => 'Contas a receber legadas', 'type' => 'LEGACY', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'AGROCOLITTI', 'name' => 'AgroColitti', 'type' => 'INTEGRATION', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ACOP_FILES', 'name' => 'Acop Files', 'type' => 'INTEGRATION', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NFSE', 'name' => 'NFS-e', 'type' => 'INTEGRATION', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'BANK_IMPORT', 'name' => 'Importação bancária', 'type' => 'BANK', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'API', 'name' => 'API genérica', 'type' => 'INTEGRATION', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('source_systems');
    }
};
