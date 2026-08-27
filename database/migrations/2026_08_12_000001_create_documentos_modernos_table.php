<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_modernos', function (Blueprint $table): void {
            $table->id();
            $table->string('entidade', 40);
            $table->unsignedInteger('registro_id');
            $table->string('nome_original');
            $table->string('caminho');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('tamanho')->default(0);
            $table->unsignedInteger('usuario_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['entidade', 'registro_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_modernos');
    }
};
