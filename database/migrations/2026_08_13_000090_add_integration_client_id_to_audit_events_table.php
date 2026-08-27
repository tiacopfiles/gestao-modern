<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('integration_client_id')->nullable()->after('source_system_id');
            $table->foreign('integration_client_id', 'audit_events_integration_client_fk')
                ->references('id')->on('integration_clients')->restrictOnDelete();
            $table->index('integration_client_id', 'audit_events_integration_client_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['integration_client_id']);
            } else {
                $table->dropForeign('audit_events_integration_client_fk');
            }
            $table->dropIndex('audit_events_integration_client_idx');
            $table->dropColumn('integration_client_id');
        });
    }
};
