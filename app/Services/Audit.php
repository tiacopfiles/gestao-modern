<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Audit
{
    public static function record(string $table, int|string $record, string $action): void
    {
        $now = now();
        DB::table('logs')->insert([
            'nome_tabela' => $table,
            'registro' => (string) $record,
            'tipo_alteracao' => $action,
            'id_usuario' => (string) (auth()->id() ?? 0),
            'data' => $now->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
