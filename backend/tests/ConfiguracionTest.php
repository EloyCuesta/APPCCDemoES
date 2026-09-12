<?php

declare(strict_types=1);

use App\Entity\ConfiguracionEntidadFiscal;
use App\Entity\ConfiguracionEstablecimiento;
use App\Entity\EntidadFiscal;
use App\Entity\Establecimiento;
use App\Kernel;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

// Ejecutar: php tests/ConfiguracionTest.php. Solo utiliza SQLite en memoria.
require dirname(__DIR__).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = '-1';

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$kernel = new Kernel('test', true);
$kernel->boot();

try {
    $container = $kernel->getContainer()->get('test.service_container');
    $em = $container->get('doctrine')->getManager();
    $connection = $em->getConnection();
    verify($connection->getDatabasePlatform() instanceof SQLitePlatform, 'La prueba requiere SQLite.');
    verify(($connection->getParams()['memory'] ?? false) === true, 'La prueba requiere una base en memoria.');
    $connection->executeStatement('PRAGMA foreign_keys = ON');
    verify((new SchemaValidator($em))->validateMapping() === [], 'El mapeo Doctrine no es válido.');
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

    $fiscalDefaults = [
        'logoPath' => null, 'idioma' => 'es', 'zonaHoraria' => 'Europe/Madrid',
        'notificacionesEmail' => true, 'notificarIncidencias' => true, 'notificarTareasPendientes' => true,
        'resumenDiarioEmail' => false, 'diasConservacionRegistros' => null, 'permitirGestionMultiEstablecimiento' => true,
    ];
    $localDefaults = [
        'horaInicioJornada' => null, 'horaFinJornada' => null, 'requiereFirmaRegistro' => false,
        'permiteRegistrosAtrasados' => false, 'maximoMinutosRegistroAtrasado' => null, 'generaIncidenciaAutomatica' => true,
        'requiereObservacionNoConforme' => true, 'requiereFotoNoConforme' => false,
        'permitirCerrarIncidenciaSinAccion' => false, 'avisarTareasPendientes' => true, 'minutosAvisoTarea' => 30,
    ];

    foreach ([
        [ConfiguracionEntidadFiscal::class, EntidadFiscal::class, 'EntidadFiscal', $fiscalDefaults],
        [ConfiguracionEstablecimiento::class, Establecimiento::class, 'Establecimiento', $localDefaults],
    ] as [$configClass, $parentClass, $suffix, $defaults]) {
        $config = new $configClass();
        $parent = new $parentClass();
        $otherParent = new $parentClass();
        verify($parent->getConfiguracion() === null, 'El titular no debe crear la configuración automáticamente.');
        foreach ($defaults as $field => $expected) {
            $getter = (is_bool($expected) ? 'is' : 'get').ucfirst($field);
            verify($config->$getter() === $expected, 'Valor inicial incorrecto: '.$configClass.'::'.$field);
        }
        verify($config->getCreatedAt() instanceof DateTimeImmutable && $config->getUpdatedAt() === null, 'Fechas iniciales incorrectas.');
        $setter = 'set'.$suffix;
        $getter = 'get'.$suffix;
        $config->$setter($parent);
        verify($parent->getConfiguracion() === $config, 'El lado propietario no sincroniza el inverso.');
        $otherParent->setConfiguracion($config);
        verify($parent->getConfiguracion() === null && $config->$getter() === $otherParent, 'La reasignación deja una relación obsoleta.');
        $replacement = new $configClass();
        $replacement->$setter($otherParent);
        verify($config->$getter() === null && $otherParent->getConfiguracion() === $replacement, 'La sustitución no desconecta la configuración anterior.');
        $otherParent->setConfiguracion(null);
        verify($replacement->$getter() === null, 'Desvincular el lado inverso no sincroniza el propietario.');
        $parent->setConfiguracion($replacement);
        verify($replacement->$getter() === $parent, 'El lado inverso no asigna el propietario.');
        $replacement->$setter(null);
        verify($parent->getConfiguracion() === null, 'Desvincular el propietario no limpia el inverso.');
    }

    $requestCount = 0;
    $request = static function (string $method, string $uri, ?array $payload = null, int $status = 200) use ($kernel, $em, &$requestCount): array {
        ++$requestCount;
        $httpRequest = Request::create($uri, $method, server: [
            'CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], content: $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        $response = $kernel->handle($httpRequest);
        $kernel->terminate($httpRequest, $response);
        $body = $response->getContent() === '' ? [] : json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        verify($response->getStatusCode() === $status, $method.' '.$uri.': '.$response->getStatusCode().' '.($body['detail'] ?? $body['description'] ?? 'Respuesta inesperada.'));
        $em->clear();

        return $body;
    };
    $violation = static function (array $body, string $property): void {
        verify(in_array($property, array_column($body['violations'] ?? [], 'propertyPath'), true), 'Falta el error del campo '.$property);
    };
    $address = ['direccion' => 'Calle Mayor 1', 'codigoPostal' => '28001', 'localidad' => 'Madrid', 'provincia' => 'Madrid'];
    $fiscal = $request('POST', '/api/entidades-fiscales', $address + ['tipo' => 'empresa', 'razonSocial' => 'Configuración S.L.', 'nif' => 'B55667788'], 201);
    $otroFiscal = $request('POST', '/api/entidades-fiscales', $address + ['tipo' => 'empresa', 'razonSocial' => 'Otra S.L.', 'nif' => 'B55667789'], 201);
    $local = $request('POST', '/api/establecimientos', $address + ['entidadFiscal' => $fiscal['@id'], 'nombre' => 'Obrador', 'tipoActividad' => 'obrador'], 201);
    $otroLocal = $request('POST', '/api/establecimientos', $address + ['entidadFiscal' => $fiscal['@id'], 'nombre' => 'Catering', 'tipoActividad' => 'catering'], 201);
    verify((int) $connection->fetchOne('SELECT COUNT(*) FROM configuracion_entidad_fiscal') === 0, 'No debe crearse configuración fiscal automáticamente.');
    verify((int) $connection->fetchOne('SELECT COUNT(*) FROM configuracion_establecimiento') === 0, 'No debe crearse configuración local automáticamente.');

    $fiscalUri = '/api/configuraciones-entidad-fiscal';
    $localUri = '/api/configuraciones-establecimiento';
    $violation($request('POST', $fiscalUri, [], 422), 'entidadFiscal');
    $violation($request('POST', $localUri, [], 422), 'establecimiento');
    $fiscalConfig = $request('POST', $fiscalUri, ['entidadFiscal' => $fiscal['@id'], 'createdAt' => '2000-01-01T00:00:00+00:00'], 201);
    $localConfig = $request('POST', $localUri, ['establecimiento' => $local['@id']], 201);
    foreach ([[$fiscalConfig, $fiscalDefaults], [$localConfig, $localDefaults]] as [$created, $defaults]) {
        foreach ($defaults as $field => $value) {
            verify(($created[$field] ?? null) === $value, 'Valor inicial API incorrecto: '.$field);
        }
        verify(!str_starts_with($created['createdAt'], '2000-') && ($created['updatedAt'] ?? null) === null, 'Las fechas no deben alterarse por API.');
    }
    verify($fiscalConfig['entidadFiscal'] === $fiscal['@id'] && $localConfig['establecimiento'] === $local['@id'], 'Las relaciones deben serializarse como IRI.');
    verify(!array_key_exists('configuracion', $request('GET', $fiscal['@id'])), 'El titular no debe serializar la configuración inversa.');
    verify(!array_key_exists('configuracion', $request('GET', $local['@id'])), 'El local no debe serializar la configuración inversa.');
    $violation($request('POST', $fiscalUri, ['entidadFiscal' => $fiscal['@id']], 422), 'entidadFiscal');
    $violation($request('POST', $localUri, ['establecimiento' => $local['@id']], 422), 'establecimiento');

    foreach (['idioma' => ['', str_repeat('a', 11)], 'zonaHoraria' => [' ', 'Zona/Inexistente', str_repeat('a', 101)], 'logoPath' => [str_repeat('a', 256)], 'diasConservacionRegistros' => [0, -1]] as $field => $values) {
        foreach ($values as $value) {
            $violation($request('PATCH', $fiscalConfig['@id'], [$field => $value], 422), $field);
        }
    }
    foreach (['maximoMinutosRegistroAtrasado', 'minutosAvisoTarea'] as $field) {
        $violation($request('PATCH', $localConfig['@id'], [$field => -1], 422), $field);
    }
    $updatedFiscal = $request('PATCH', $fiscalConfig['@id'], ['idioma' => 'en-GB', 'zonaHoraria' => 'Europe/London', 'diasConservacionRegistros' => 1, 'notificacionesEmail' => false, 'logoPath' => 'logos/cliente.svg']);
    verify($updatedFiscal['createdAt'] === $fiscalConfig['createdAt'] && !empty($updatedFiscal['updatedAt']), 'updatedAt debe actualizarse sin cambiar createdAt.');
    verify($request('GET', $fiscalConfig['@id'])['idioma'] === 'en-GB', 'No se ha persistido el idioma.');
    $updatedLocal = $request('PATCH', $localConfig['@id'], [
        'horaInicioJornada' => '22:00:00', 'horaFinJornada' => '06:00:00',
        'maximoMinutosRegistroAtrasado' => 0, 'minutosAvisoTarea' => 0, 'updatedAt' => '2000-01-01T00:00:00+00:00',
    ]);
    verify($updatedLocal['horaInicioJornada'] === '22:00:00' && $updatedLocal['horaFinJornada'] === '06:00:00', 'Debe admitirse una jornada nocturna.');
    verify($updatedLocal['createdAt'] === $localConfig['createdAt'] && !str_starts_with($updatedLocal['updatedAt'], '2000-'), 'Las fechas deben gestionarse automáticamente.');
    verify($request('GET', $localConfig['@id'])['horaFinJornada'] === '06:00:00', 'No se ha persistido la hora.');
    $request('PATCH', $fiscalConfig['@id'], ['diasConservacionRegistros' => null]);
    $request('PATCH', $localConfig['@id'], ['horaInicioJornada' => null, 'horaFinJornada' => null, 'maximoMinutosRegistroAtrasado' => null]);

    // Reasignación a un titular libre y comprobación de ambos lados tras hidratar.
    $request('PATCH', $fiscalConfig['@id'], ['entidadFiscal' => $otroFiscal['@id']]);
    verify($em->find(EntidadFiscal::class, $fiscal['id'])->getConfiguracion() === null, 'El antiguo titular mantiene la configuración.');
    verify($em->find(EntidadFiscal::class, $otroFiscal['id'])->getConfiguracion()?->getId() === $fiscalConfig['id'], 'Falta la configuración en el nuevo titular.');
    $request('PATCH', $fiscalConfig['@id'], ['entidadFiscal' => $fiscal['@id']]);
    $segundaConfig = $request('POST', $fiscalUri, ['entidadFiscal' => $otroFiscal['@id']], 201);
    $violation($request('PATCH', $fiscalConfig['@id'], ['entidadFiscal' => $otroFiscal['@id']], 422), 'entidadFiscal');
    $segundaConfigLocal = $request('POST', $localUri, ['establecimiento' => $otroLocal['@id']], 201);
    $violation($request('PATCH', $localConfig['@id'], ['establecimiento' => $otroLocal['@id']], 422), 'establecimiento');
    $request('GET', $fiscalUri);
    $request('GET', $localUri);

    // Las preferencias no deben activar las reglas operativas que quedan fuera del alcance.
    $usuario = $request('POST', '/api/usuarios', ['nombre' => 'Ana', 'apellidos' => 'García', 'email' => 'ana@example.com'], 201);
    $plan = $request('POST', '/api/planes-control', ['establecimiento' => $local['@id'], 'tipo' => 'temperaturas', 'nombre' => 'Temperaturas'], 201);
    $tarea = $request('POST', '/api/tareas', ['establecimiento' => $local['@id'], 'planControl' => $plan['@id'], 'nombre' => 'Cámara obrador', 'frecuencia' => 'diaria'], 201);
    $registro = $request('POST', '/api/registros', ['establecimiento' => $local['@id'], 'tarea' => $tarea['@id'], 'usuario' => $usuario['@id'], 'conforme' => false, 'fechaHora' => '2020-01-01T08:00:00+00:00', 'valorNumerico' => '8.500'], 201);
    $before = $request('GET', $registro['@id']);
    verify((int) $connection->fetchOne('SELECT COUNT(*) FROM incidencia') === 0, 'No deben generarse incidencias automáticamente.');
    $request('PATCH', $fiscalConfig['@id'], ['diasConservacionRegistros' => 1]);
    $request('PATCH', $localConfig['@id'], ['requiereFotoNoConforme' => true, 'requiereFirmaRegistro' => true, 'generaIncidenciaAutomatica' => false]);
    verify($request('GET', $registro['@id']) === $before, 'Cambiar preferencias no debe modificar históricos.');

    // Comprobar UNIQUE, defaults y FK en la BD de prueba sin depender de Validator.
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    foreach ([['configuracion_entidad_fiscal', 'entidad_fiscal_id', $fiscal['id']], ['configuracion_establecimiento', 'establecimiento_id', $local['id']]] as [$table, $fk, $id]) {
        try {
            $connection->insert($table, [$fk => $id, 'created_at' => $now]);
            throw new RuntimeException('La BD permite duplicar '.$table);
        } catch (UniqueConstraintViolationException) {
        }
        try {
            $connection->insert($table, [$fk => 99999999, 'created_at' => $now]);
            throw new RuntimeException('La BD permite una FK inexistente en '.$table);
        } catch (ForeignKeyConstraintViolationException) {
        }
    }
    $connection->delete('configuracion_entidad_fiscal', ['id' => $segundaConfig['id']]);
    $connection->insert('configuracion_entidad_fiscal', ['entidad_fiscal_id' => $otroFiscal['id'], 'created_at' => $now]);
    $dbFiscal = $connection->fetchAssociative('SELECT * FROM configuracion_entidad_fiscal WHERE entidad_fiscal_id = ?', [$otroFiscal['id']]);
    verify($dbFiscal['idioma'] === 'es' && $dbFiscal['zona_horaria'] === 'Europe/Madrid' && (int) $dbFiscal['notificaciones_email'] === 1 && (int) $dbFiscal['resumen_diario_email'] === 0, 'Defaults fiscales incorrectos en la BD.');
    $connection->delete('configuracion_establecimiento', ['id' => $segundaConfigLocal['id']]);
    $connection->insert('configuracion_establecimiento', ['establecimiento_id' => $otroLocal['id'], 'created_at' => $now]);
    $dbLocal = $connection->fetchAssociative('SELECT * FROM configuracion_establecimiento WHERE establecimiento_id = ?', [$otroLocal['id']]);
    verify((int) $dbLocal['minutos_aviso_tarea'] === 30 && (int) $dbLocal['requiere_firma_registro'] === 0 && (int) $dbLocal['genera_incidencia_automatica'] === 1, 'Defaults del local incorrectos en la BD.');
    $connection->delete('configuracion_entidad_fiscal', ['id' => $fiscalConfig['id']]);
    $connection->delete('configuracion_establecimiento', ['id' => $localConfig['id']]);
    verify((int) $connection->fetchOne('SELECT COUNT(*) FROM registro_appcc') === 1, 'Eliminar configuración en memoria ha borrado un registro.');
    verify((int) $connection->fetchOne('SELECT COUNT(*) FROM establecimiento') === 2, 'Eliminar configuración en memoria ha borrado un local.');

    echo "OK: $requestCount peticiones API, configuración, defaults, OneToOne, UNIQUE, FK, validaciones y conservación de históricos.\n";
} finally {
    $kernel->shutdown();
}
