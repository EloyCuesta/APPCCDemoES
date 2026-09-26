<?php

declare(strict_types=1);

// Guardia compartida de los helpers Windows, antes de migrar/seed/procesar.
require dirname(__DIR__).'/vendor/autoload.php';

try {
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
    $env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '';
    $url = parse_url($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '');
    $database = ltrim($url['path'] ?? '', '/');
    if ($env !== 'dev' || !is_array($url)
        || !in_array($url['scheme'] ?? '', ['postgresql', 'postgres'], true)
        || !in_array($url['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)
        || !preg_match('/^appcc_demo_es(?:_[a-z0-9]+)*$/D', $database)
        || str_ends_with($database, '_test')) {
        throw new RuntimeException('Entorno no autorizado.');
    }
    fwrite(STDOUT, "Desarrollo local: $database (dev, PostgreSQL loopback).\n");
} catch (Throwable) {
    fwrite(STDERR, "Helpers exclusivos de APP_ENV=dev y PostgreSQL local, base appcc_demo_es o appcc_demo_es_<nombre>, nunca *_test. Revise los dotenv y las variables del proceso; no se muestra la URL.\n");
    exit(1);
}
