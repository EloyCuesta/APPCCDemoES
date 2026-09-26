<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LocalDevelopmentEnvironmentTest extends TestCase
{
    #[DataProvider('entornos')]
    public function testHelpersRechazanDestinosNoAutorizadosSinMostrarCredenciales(string $environment, string $host, string $database, int $exitCode): void
    {
        $process = new Process([PHP_BINARY, '-d', 'variables_order=EGPCS', 'bin/check-local-dev.php'], dirname(__DIR__), [
            'APP_ENV' => $environment,
            // Simula una terminal nueva, sin las variables dotenv del padre PHPUnit.
            'SYMFONY_DOTENV_VARS' => false,
            'DATABASE_URL' => "postgresql://demo:secreto-no-imprimir@$host:5432/$database?serverVersion=18",
        ]);
        self::assertSame($exitCode, $process->run());
        self::assertStringNotContainsString('secreto-no-imprimir', $process->getOutput().$process->getErrorOutput());
    }

    public static function entornos(): iterable
    {
        yield 'local' => ['dev', '127.0.0.1', 'appcc_demo_es', 0];
        yield 'demo nueva' => ['dev', 'localhost', 'appcc_demo_es_aceptacion', 0];
        yield 'produccion' => ['prod', '127.0.0.1', 'appcc_demo_es', 1];
        yield 'entorno test' => ['test', '127.0.0.1', 'appcc_demo_es', 1];
        yield 'base test' => ['dev', '127.0.0.1', 'appcc_demo_es_test', 1];
        yield 'servidor remoto' => ['dev', 'database.example.com', 'appcc_demo_es', 1];
        yield 'base ajena' => ['dev', '127.0.0.1', 'produccion', 1];
    }
}
