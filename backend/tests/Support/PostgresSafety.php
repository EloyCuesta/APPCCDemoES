<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

final class PostgresSafety
{
    public static function assertTestDatabase(Connection $db): void
    {
        $env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '';
        $name = $db->getDatabase();
        // Allowlist adicional: no basta con que cualquier base se llame *_test.
        $allowed = $_SERVER['APPCC_TEST_DATABASE'] ?? $_ENV['APPCC_TEST_DATABASE'] ?? '';
        $developmentName = $_SERVER['APPCC_DEV_DATABASE'] ?? $_ENV['APPCC_DEV_DATABASE'] ?? '';
        $localFile = dirname(__DIR__, 2).'/.env.local';
        if (is_file($localFile)) {
            $localVars = (new \Symfony\Component\Dotenv\Dotenv())->parse(file_get_contents($localFile));
            if (isset($localVars['DATABASE_URL'])) {
                $developmentName = (new \Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse($localVars['DATABASE_URL'])['dbname'] ?? $developmentName;
            }
        }
        if ($env !== 'test' || !$db->getDatabasePlatform() instanceof PostgreSQLPlatform
            || !str_ends_with($name, '_test') || $allowed === '' || $name !== $allowed || $developmentName === $name
            || $db->fetchOne('SELECT current_database()') !== $allowed) {
            throw new \RuntimeException('Operación de pruebas rechazada: entorno, plataforma o base no autorizados. No se muestra la URL.');
        }
    }

    public static function truncate(Connection $db): void
    {
        self::assertTestDatabase($db);
        $tables = array_filter($db->createSchemaManager()->listTableNames(), static fn ($name) => $name !== 'doctrine_migration_versions');
        if ($tables !== []) {
            $db->executeStatement('TRUNCATE '.implode(', ', array_map($db->getDatabasePlatform()->quoteSingleIdentifier(...), $tables)).' RESTART IDENTITY');
        }
    }
}
