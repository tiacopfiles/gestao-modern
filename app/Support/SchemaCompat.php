<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checagens de schema que funcionam também no MariaDB 10.1.
 *
 * `Schema::hasColumn()` do Laravel lê `information_schema.columns` pedindo,
 * entre outras, a coluna `generation_expression` — que só passou a existir no
 * MariaDB 10.2. No servidor de produção, que roda 10.1.10, a chamada estoura com
 * "Unknown column 'generation_expression' in 'field list'" e derruba a migration
 * ou a tela que dependia dela.
 *
 * Aqui a via normal é tentada primeiro; só quando ela falha é que caímos para
 * uma consulta que existe em qualquer versão. Assim o comportamento continua o
 * do framework onde o framework funciona.
 */
class SchemaCompat
{
    public static function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (Throwable) {
            return self::hasColumnViaInformationSchema($table, $column);
        }
    }

    private static function hasColumnViaInformationSchema(string $table, string $column): bool
    {
        $prefixed = DB::getTablePrefix().$table;

        try {
            return DB::selectOne(
                'SELECT 1 AS existe FROM information_schema.columns '
                .'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1',
                [$prefixed, $column],
            ) !== null;
        } catch (Throwable) {
            // Sem introspecção possível, o mais seguro é assumir que a coluna
            // não está lá: quem chama usa isso para decidir se cria ou se
            // esconde um campo, e o erro barato é o de não oferecer.
            return false;
        }
    }
}
