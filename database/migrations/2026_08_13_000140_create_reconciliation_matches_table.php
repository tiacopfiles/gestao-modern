<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_matches', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('reconciliation_session_id');
            $table->string('status', 20)->default('CONFIRMED');
            $table->string('method', 20)->default('MANUAL');
            $table->unsignedInteger('confirmed_by');
            $table->timestamp('confirmed_at');
            $table->unsignedInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->string('correlation_id', 64);
            $table->timestamps();

            $table->foreign('reconciliation_session_id', 'reconciliation_matches_session_fk')
                ->references('id')->on('reconciliation_sessions')->restrictOnDelete();
            $table->index(
                ['reconciliation_session_id', 'status', 'created_at'],
                'reconciliation_matches_session_status_date_idx',
            );
            $table->index('correlation_id', 'reconciliation_matches_correlation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_matches');
    }
};
