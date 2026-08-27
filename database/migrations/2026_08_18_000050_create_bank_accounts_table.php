<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Conta bancária de verdade — banco, agência e número — separada da EMPRESA.
 *
 * O sistema antigo chama de "conta" o que na prática é a empresa/centro:
 * `Acop Files`, `Global Box`, `Duemagem`. Isso não é conta bancária, e a
 * diferença ficou explícita nas planilhas de conciliação, onde cada arquivo tem
 * a empresa numa linha e a conta em outra:
 *
 *   Acop Files Organização e Guarda de Documentos Ltda
 *   Banco Itaú - Agência 6260 - C/C 13377-9
 *
 * Uma empresa pode ter várias contas, e é a conta — não a empresa — que tem
 * extrato, saldo e conciliação. Enquanto os dois conceitos estiverem no mesmo
 * campo, não há como saber por qual banco um título saiu; foi exatamente o que
 * deixou 7 pagamentos de janeiro/2026 da Acop Files sem explicação.
 *
 * O vínculo título → conta bancária NÃO é criado aqui. Ele depende do extrato
 * real: é o match com o fato bancário que prova por onde o dinheiro passou.
 * Adivinhar pelo nome da empresa produziria um vínculo que parece informação e
 * não é.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();

            // A empresa/centro herdada do legado (`contas`). Fica anulável porque
            // uma conta pode ser cadastrada antes de se saber a qual empresa
            // pertence — e um palpite aqui seria pior que o vazio.
            $table->unsignedInteger('company_id')->nullable();
            $table->string('company_name', 191)->nullable();

            $table->string('bank_name', 120);
            $table->string('bank_code', 10)->nullable();      // 341 = Itaú
            $table->string('agency', 20);
            $table->string('number', 30);
            $table->string('label', 191)->nullable();          // como aparece na planilha

            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['bank_code', 'agency', 'number'], 'bank_accounts_identity_uq');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
