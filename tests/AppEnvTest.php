<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/app-env.php';

final class AppEnvTest extends TestCase
{
    public function testDeclaredEnvironmentWins(): void
    {
        self::assertSame('prod', app_env_resolve('prod', false));
        self::assertSame('preprod', app_env_resolve('preprod', true));
        self::assertSame('local', app_env_resolve('local', false));
    }

    public function testSynonymsAndWhitespace(): void
    {
        self::assertSame('prod', app_env_resolve("  Production\n", false));
        self::assertSame('preprod', app_env_resolve('staging', false));
        self::assertSame('local', app_env_resolve('development', false));
    }

    public function testUndeclaredFallsBackToLocalOnDevMachine(): void
    {
        self::assertSame('local', app_env_resolve(null, true));
        self::assertSame('local', app_env_resolve('', true));
    }

    public function testUndeclaredServerIsNeverProd(): void
    {
        self::assertSame('preprod', app_env_resolve(null, false));
        self::assertSame('preprod', app_env_resolve('', false));
        self::assertSame('preprod', app_env_resolve('n-importe-quoi', false));
    }
}
