<?php

namespace Tests\Unit;

use Acop\Homologation\HomologationGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HomologationGuardTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    private const VARIABLES = [
        'APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
        'DB_USERNAME', 'DB_PASSWORD', 'DB_PREFIX', 'DB_URL',
        'HOMOLOGATION_ALLOW_DESTRUCTIVE',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2).'/tools/homologation/HomologationGuard.php';

        foreach (self::VARIABLES as $name) {
            $this->originalEnvironment[$name] = getenv($name);
        }

        $this->setSafeShape();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        parent::tearDown();
    }

    #[DataProvider('unsafeShapes')]
    public function test_guard_aborts_unsafe_shapes_before_opening_a_connection(
        string $variable,
        string $value,
        string $expectedMessage,
    ): void {
        putenv("{$variable}={$value}");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        HomologationGuard::assertSafe();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function unsafeShapes(): iterable
    {
        yield 'production environment' => ['APP_ENV', 'production', 'APP_ENV deve ser testing ou homologation'];
        yield 'remote host' => ['DB_HOST', '10.0.0.220', 'DB_HOST deve ser loopback'];
        yield 'unsafe database name' => ['DB_DATABASE', 'financeiro', 'DB_DATABASE deve começar com acop_hml_ ou acop_test_'];
        yield 'wrong prefix' => ['DB_PREFIX', '', 'DB_PREFIX deve ser avt_'];
        yield 'connection URL override' => ['DB_URL', 'mysql://unsafe.invalid/database', 'DB_URL não pode sobrescrever'];
        yield 'missing acknowledgement' => ['HOMOLOGATION_ALLOW_DESTRUCTIVE', '', 'confirmação destrutiva'];
    }

    private function setSafeShape(): void
    {
        $values = [
            'APP_ENV' => 'homologation',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '33101',
            'DB_DATABASE' => 'acop_hml_guard_test',
            'DB_USERNAME' => 'synthetic',
            'DB_PASSWORD' => 'synthetic',
            'DB_PREFIX' => 'avt_',
            'DB_URL' => '',
            'HOMOLOGATION_ALLOW_DESTRUCTIVE' => HomologationGuard::DESTRUCTIVE_ACK,
        ];

        foreach ($values as $name => $value) {
            putenv("{$name}={$value}");
        }
    }
}
