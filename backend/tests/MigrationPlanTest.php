<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\PostgreSQLSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\SchemaManagerFactory;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20260911130812;
use DoctrineMigrations\Version20260911132708;
use DoctrineMigrations\Version20260911151914;
use Psr\Log\NullLogger;

// Compara el SQL planificado. No abre conexión ni ejecuta SQL o migraciones en una BD.
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/migrations/Version20260911130812.php';
require dirname(__DIR__).'/migrations/Version20260911132708.php';
require dirname(__DIR__).'/migrations/Version20260911151914.php';

$dbalConfig = new Configuration();
$dbalConfig->setSchemaManagerFactory(new class implements SchemaManagerFactory {
    public function createSchemaManager(Connection $connection): AbstractSchemaManager
    {
        return new class($connection, $connection->getDatabasePlatform()) extends PostgreSQLSchemaManager {
            public function createSchemaConfig(): SchemaConfig
            {
                // Evitar la consulta SELECT current_schema() de DBAL.
                $config = new SchemaConfig();
                $config->setName('public');
                $config->setMaxIdentifierLength(63);

                return $config;
            }
        };
    }
});
$connection = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '18'], $dbalConfig);
$config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__).'/src/Entity'], true);
$config->setNamingStrategy(new UnderscoreNamingStrategy());
$config->setIdentityGenerationPreferences([PostgreSQLPlatform::class => ClassMetadata::GENERATOR_TYPE_IDENTITY]);
$em = new EntityManager($connection, $config);
$expected = (new SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata());
$planned = [];

foreach ([Version20260911130812::class, Version20260911132708::class, Version20260911151914::class] as $class) {
    $migration = new $class($connection, new NullLogger());
    $migration->up(new Schema());
    foreach ($migration->getSql() as $query) {
        $planned[] = $query->getStatement();
    }
}

sort($expected);
sort($planned);
if ($planned !== $expected) {
    throw new RuntimeException('El SQL de las migraciones no coincide con el modelo: '.json_encode([
        'faltante' => array_values(array_diff($expected, $planned)),
        'sobrante' => array_values(array_diff($planned, $expected)),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}

if ($connection->isConnected()) {
    throw new RuntimeException('Esta prueba no debe abrir una conexión.');
}

echo "OK: las tres migraciones generan exactamente el esquema PostgreSQL de las 13 entidades, sin conectar a la BD.\n";
