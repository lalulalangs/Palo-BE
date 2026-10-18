<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseConnectionGuardTest extends TestCase
{
    public function test_production_rejects_sqlite_connection(): void
    {
        $this->expectException(RuntimeException::class);

        AppServiceProvider::ensureDatabaseConnectionIsSafe('production', 'sqlite');
    }

    public function test_production_rejects_non_pgsql_connection(): void
    {
        $this->expectException(RuntimeException::class);

        AppServiceProvider::ensureDatabaseConnectionIsSafe('production', 'mysql');
    }

    #[DataProvider('safeCombinations')]
    public function test_safe_combinations_do_not_throw(string $environment, string $connection): void
    {
        AppServiceProvider::ensureDatabaseConnectionIsSafe($environment, $connection);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{environment: string, connection: string}>
     */
    public static function safeCombinations(): array
    {
        return [
            'local sqlite' => ['environment' => 'local', 'connection' => 'sqlite'],
            'testing sqlite' => ['environment' => 'testing', 'connection' => 'sqlite'],
            'production pgsql' => ['environment' => 'production', 'connection' => 'pgsql'],
        ];
    }
}
