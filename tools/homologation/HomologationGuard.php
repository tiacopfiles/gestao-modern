<?php

declare(strict_types=1);

namespace Acop\Homologation;

use PDO;
use RuntimeException;

final class HomologationGuard
{
    public const DESTRUCTIVE_ACK = 'I_UNDERSTAND_THIS_DATABASE_WILL_BE_DESTROYED';

    /** @return array<string, string|int> */
    public static function assertSafe(bool $rejectProtectedTables = false): array
    {
        $environment = self::env('APP_ENV');
        $host = self::env('DB_HOST');
        $database = self::env('DB_DATABASE');
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $username = self::env('DB_USERNAME');
        $password = (string) getenv('DB_PASSWORD');
        $connection = self::env('DB_CONNECTION');
        $prefix = (string) getenv('DB_PREFIX');

        if (! in_array($environment, ['testing', 'homologation'], true)) {
            throw new RuntimeException('ABORT: APP_ENV deve ser testing ou homologation.');
        }
        if (! in_array($connection, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('ABORT: DB_CONNECTION deve ser mysql ou mariadb.');
        }
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('ABORT: DB_HOST deve ser loopback; host remoto não é permitido.');
        }
        if (! preg_match('/^acop_(?:hml|test)_[a-z0-9_]+$/', $database)) {
            throw new RuntimeException('ABORT: DB_DATABASE deve começar com acop_hml_ ou acop_test_.');
        }
        if ($prefix !== 'avt_') {
            throw new RuntimeException('ABORT: DB_PREFIX deve ser avt_ para reproduzir o alvo documentado.');
        }
        if (getenv('DB_URL') !== false && trim((string) getenv('DB_URL')) !== '') {
            throw new RuntimeException('ABORT: DB_URL não pode sobrescrever a conexão explícita de homologação.');
        }
        if (getenv('HOMOLOGATION_ALLOW_DESTRUCTIVE') !== self::DESTRUCTIVE_ACK) {
            throw new RuntimeException('ABORT: confirmação destrutiva de homologação ausente ou incorreta.');
        }
        if ($port < 1024 || $port > 65535) {
            throw new RuntimeException('ABORT: DB_PORT fora da faixa local permitida.');
        }

        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true],
        );
        $server = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $currentDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $serverHost = (string) $pdo->query('SELECT @@hostname')->fetchColumn();
        $isolation = (string) $pdo->query('SELECT @@tx_isolation')->fetchColumn();

        if (! preg_match('/^10\.1\./', $server)) {
            throw new RuntimeException("ABORT: versão incompatível; esperado MariaDB 10.1.x, recebido {$server}.");
        }
        if (! hash_equals($database, $currentDatabase)) {
            throw new RuntimeException('ABORT: DATABASE() não corresponde ao database autorizado.');
        }

        if ($rejectProtectedTables) {
            $protected = ['avt_lancamentos', 'avt_recebimentos', 'avt_movimentos', 'avt_conciliacoes'];
            $placeholders = implode(',', array_fill(0, count($protected), '?'));
            $statement = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name IN ({$placeholders})");
            $statement->execute([$database, ...$protected]);
            $found = $statement->fetchAll(PDO::FETCH_COLUMN);
            if ($found !== []) {
                throw new RuntimeException('ABORT: o database contém tabelas legadas protegidas: '.implode(', ', $found));
            }
        }

        return [
            'status' => 'SAFE_HOMOLOGATION_TARGET',
            'app_env' => $environment,
            'db_host' => $host,
            'db_port' => $port,
            'db_database' => $database,
            'db_prefix' => $prefix,
            'server_version' => $server,
            'server_hostname' => $serverHost,
            'transaction_isolation' => $isolation,
        ];
    }

    private static function env(string $name): string
    {
        $value = trim((string) getenv($name));
        if ($value === '') {
            throw new RuntimeException("ABORT: variável {$name} é obrigatória.");
        }

        return $value;
    }
}
