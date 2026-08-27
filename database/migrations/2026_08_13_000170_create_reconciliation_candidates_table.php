<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_candidates', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('reconciliation_session_id');
            $table->foreignId('reconciliation_match_id')->nullable();
            $table->string('type', 20);
            $table->string('status', 16)->default('PENDING');
            $table->unsignedTinyInteger('score');
            $table->string('confidence', 12);
            $table->string('engine_version', 40);
            $table->char('signature_hash', 64);
            $table->longText('evidence');
            $table->unsignedBigInteger('generated_by');
            $table->timestamp('generated_at');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->uuid('correlation_id');
            $table->timestamps();

            $table->foreign('reconciliation_session_id', 'recon_candidates_session_fk')
                ->references('id')->on('reconciliation_sessions')->restrictOnDelete();
            $table->foreign('reconciliation_match_id', 'recon_candidates_match_fk')
                ->references('id')->on('reconciliation_matches')->nullOnDelete();
            $table->unique(['reconciliation_session_id', 'engine_version', 'signature_hash'], 'reconciliation_candidate_signature_unique');
            $table->index(['reconciliation_session_id', 'status', 'score'], 'reconciliation_candidate_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_candidates');
    }
};
