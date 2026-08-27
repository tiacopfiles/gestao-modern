<?php

declare(strict_types=1);

namespace App\Integration;

use PDO;
use RuntimeException;

/**
 * Leitor SOMENTE LEITURA dos bancos de origem (Contas a Pagar / Contas a Receber).
 *
 * Estes bancos são de PRODUÇÃO: são os sistemas que as funcionárias usam todo
 * dia. A trava aqui não é uma convenção de código, é executada: toda instrução
 * passa por `assertReadOnly()` antes de chegar ao servidor, e qualquer coisa que
 * não comece com SELECT/SHOW/DESCRIBE/EXPLAIN é recusada — assim como qualquer
 * SQL que contenha uma palavra de escrita em qualquer posição.
 *
 * A conexão também é aberta em modo somente leitura no servidor
 * (`SET SESSION TRANSACTION READ ONLY`), de modo que uma escrita que
 * escapasse do guard ainda seria recusada pelo próprio MariaDB.
 *
 * Este arquivo nunca deve ganhar um método de escrita.
 */
final class OriginReader
{
    /** Instruções permitidas — a lista é fechada, não extensível por engano. */
    private const ALLOWED_PREFIXES = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];

    /** Palavras que jamais podem aparecer em uma consulta de leitura. */
    private const FORBIDDEN = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'ALTER', 'DROP', 'TRUNCATE',
        'CREATE', 'GRANT', 'REVOKE', 'RENAME', 'LOCK', 'CALL', 'SET ',
        'LOAD ', 'INTO OUTFILE', 'INTO DUMPFILE', 'HANDLER', 'FLUSH', 'KILL',
    ];

    private PDO $pdo;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        string $username,
        string $password,
    ) {
        $this->pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Strings, nunca conversão numérica automática: dinheiro precisa
                // chegar como veio do banco, sem passar por float.
                PDO::ATTR_STRINGIFY_FETCHES => true,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        // Trava do lado do servidor, além do guard do lado do cliente.
        $this->pdo->exec('SET SESSION TRANSACTION READ ONLY');
    }

    /**
     * @param  array<string|int, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $this->assertReadOnly($sql);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll();
    }

    /** @param array<string|int, mixed> $bindings */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $rows = $this->select($sql, $bindings);

        return $rows === [] ? null : reset($rows[0]);
    }

    public function database(): string
    {
        return $this->database;
    }

    public function describe(): string
    {
        return $this->host.':'.$this->port.'/'.$this->database;
    }

    private function assertReadOnly(string $sql): void
    {
        $normalized = strtoupper(ltrim($sql));

        $allowed = false;
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $allowed = true;
                break;
            }
        }
        if (! $allowed) {
            throw new RuntimeException('ABORT: somente SELECT/SHOW/DESCRIBE/EXPLAIN são permitidos contra a origem.');
        }

        foreach (self::FORBIDDEN as $word) {
            if (str_contains($normalized, $word)) {
                throw new RuntimeException("ABORT: instrução de escrita detectada ({$word}) — recusada.");
            }
        }

        if (str_contains($normalized, ';')) {
            throw new RuntimeException('ABORT: múltiplas instruções não são permitidas.');
        }
    }
}
