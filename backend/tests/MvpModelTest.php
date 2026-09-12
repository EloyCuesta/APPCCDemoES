<?php

declare(strict_types=1);

use App\Entity\EntidadFiscal;
use App\Entity\Establecimiento;
use App\Entity\TareaAPPCC;
use App\Kernel;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

// Ejecutar: php tests/MvpModelTest.php. No conecta con PostgreSQL.
require dirname(__DIR__).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = '-1';

function expect(bool $condition, string $message): void
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
    expect($connection->getDatabasePlatform() instanceof SQLitePlatform, 'La prueba requiere SQLite.');
    expect(($connection->getParams()['memory'] ?? false) === true, 'La prueba requiere una base en memoria.');
    $connection->executeStatement('PRAGMA foreign_keys = ON');
    expect((new SchemaValidator($em))->validateMapping() === [], 'El mapeo Doctrine no es válido.');
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

    // Comprueba sincronización bidireccional al añadir, reasignar y quitar.
    $fiscalA = new EntidadFiscal();
    $fiscalB = new EntidadFiscal();
    $local = new Establecimiento();
    $fiscalA->addEstablecimiento($local);
    expect($local->getEntidadFiscal() === $fiscalA, 'La colección no actualiza el titular.');
    $local->setEntidadFiscal($fiscalB);
    expect(!$fiscalA->getEstablecimientos()->contains($local) && $fiscalB->getEstablecimientos()->contains($local), 'Reasignación incoherente.');
    $fiscalB->removeEstablecimiento($local);
    expect($local->getEntidadFiscal() === null, 'La eliminación de la colección no sincroniza la relación.');

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
        expect($response->getStatusCode() === $status, $method.' '.$uri.': '.$response->getStatusCode().' '.($body['detail'] ?? $body['description'] ?? 'Respuesta inesperada.'));
        $em->clear();

        return $body;
    };
    $violation = static function (array $body, string $property): void {
        expect(in_array($property, array_column($body['violations'] ?? [], 'propertyPath'), true), 'Falta el error del campo '.$property);
    };

    $address = ['direccion' => 'Calle Mayor 1', 'codigoPostal' => '28001', 'localidad' => 'Madrid', 'provincia' => 'Madrid'];
    $fiscal = $request('POST', '/api/entidades-fiscales', $address + ['tipo' => 'empresa', 'razonSocial' => 'Empresa MVP', 'nif' => 'B11223344'], 201);
    $restaurante = $request('POST', '/api/establecimientos', $address + ['entidadFiscal' => $fiscal['@id'], 'nombre' => 'Restaurante', 'tipoActividad' => 'restaurante'], 201);
    $carniceria = $request('POST', '/api/establecimientos', $address + ['entidadFiscal' => $fiscal['@id'], 'nombre' => 'Carnicería', 'tipoActividad' => 'carniceria'], 201);
    expect($restaurante['entidadFiscal'] === $fiscal['@id'], 'El titular debe serializarse como IRI.');
    expect(!isset($request('GET', $fiscal['@id'])['establecimientos']), 'No deben serializarse las colecciones inversas.');
    $violation($request('POST', '/api/establecimientos', $address + ['nombre' => 'Incompleto', 'tipoActividad' => 'hotel'], 422), 'entidadFiscal');

    $userPayload = ['nombre' => 'Carlos', 'apellidos' => 'Martín', 'email' => ' CARLOS@EXAMPLE.COM '];
    $usuario = $request('POST', '/api/usuarios', $userPayload, 201);
    expect($usuario['email'] === 'carlos@example.com', 'Debe normalizarse el correo del usuario.');
    $violation($request('POST', '/api/usuarios', $userPayload, 422), 'email');
    $membership = ['usuario' => $usuario['@id'], 'establecimiento' => $restaurante['@id'], 'rol' => 'responsable'];
    $vinculo = $request('POST', '/api/usuarios-establecimientos', $membership, 201);
    $violation($request('POST', '/api/usuarios-establecimientos', $membership, 422), 'usuario');
    $request('POST', '/api/usuarios-establecimientos', array_replace($membership, ['establecimiento' => $carniceria['@id'], 'rol' => 'auditor']), 201);

    $plan = $request('POST', '/api/planes-control', ['establecimiento' => $restaurante['@id'], 'tipo' => 'temperaturas', 'nombre' => 'Temperaturas'], 201);
    $otroPlan = $request('POST', '/api/planes-control', ['establecimiento' => $carniceria['@id'], 'tipo' => 'recepcion', 'nombre' => 'Recepción'], 201);
    $punto = $request('POST', '/api/puntos-control', ['establecimiento' => $restaurante['@id'], 'nombre' => 'Cámara 1', 'tipo' => 'camara_frigorifica'], 201);
    $otroPunto = $request('POST', '/api/puntos-control', ['establecimiento' => $carniceria['@id'], 'nombre' => 'Recepción carne', 'tipo' => 'recepcion'], 201);
    $plantilla = $request('POST', '/api/plantillas-appcc', ['nombre' => 'Recepción carnicería', 'tipoActividad' => 'carniceria', 'configuracion' => ['planes' => [['tipo' => 'recepcion', 'campos' => ['producto', 'temperatura', 'estadoEnvase']]]]], 201);
    expect($plantilla['configuracion']['planes'][0]['tipo'] === 'recepcion', 'La plantilla debe conservar su configuración.');

    $taskPayload = [
        'establecimiento' => $restaurante['@id'], 'planControl' => $plan['@id'], 'puntoControl' => $punto['@id'],
        'nombre' => 'Temperatura cámara 1', 'frecuencia' => 'diaria', 'horaPrevista' => '08:30:00',
        'limiteMinimo' => '-2.000', 'limiteMaximo' => '4.000', 'unidad' => '°C', 'configuracion' => ['tipoRespuesta' => 'numero'],
    ];
    $violation($request('POST', '/api/tareas', array_replace($taskPayload, ['planControl' => $otroPlan['@id']]), 422), 'planControl');
    $violation($request('POST', '/api/tareas', array_replace($taskPayload, ['puntoControl' => $otroPunto['@id']]), 422), 'puntoControl');
    $violation($request('POST', '/api/tareas', array_replace($taskPayload, ['limiteMinimo' => '5']), 422), 'limiteMaximo');
    $violation($request('POST', '/api/tareas', array_replace($taskPayload, ['limiteMaximo' => '4.0001']), 422), 'limiteMaximo');
    $violation($request('POST', '/api/tareas', array_replace($taskPayload, ['limiteMinimo' => '']), 422), 'limiteMinimo');
    $tarea = $request('POST', '/api/tareas', $taskPayload, 201);
    expect($tarea['horaPrevista'] === '08:30:00', 'La hora prevista debe serializarse sin fecha.');
    expect($tarea['planControl'] === $plan['@id'] && $tarea['puntoControl'] === $punto['@id'], 'Las relaciones deben ser IRIs.');
    $recepcion = $request('POST', '/api/tareas', [
        'establecimiento' => $carniceria['@id'], 'planControl' => $otroPlan['@id'], 'nombre' => 'Recepción carne',
        'frecuencia' => 'por_recepcion', 'configuracion' => ['campos' => ['producto', 'temperatura', 'estadoEnvase']],
    ], 201);

    $recordPayload = ['tarea' => $tarea['@id'], 'establecimiento' => $restaurante['@id'], 'usuario' => $usuario['@id'], 'conforme' => false, 'valorNumerico' => '8.125'];
    $violation($request('POST', '/api/registros', array_replace($recordPayload, ['establecimiento' => $carniceria['@id']]), 422), 'tarea');
    $missingConforme = $recordPayload;
    unset($missingConforme['conforme']);
    $violation($request('POST', '/api/registros', $missingConforme, 422), 'conforme');
    $registro = $request('POST', '/api/registros', $recordPayload, 201);
    expect($registro['conforme'] === false && $registro['valorNumerico'] === '8.125', 'El registro debe conservar el resultado y la precisión.');
    $datos = ['proveedor' => 'Distribuciones Norte', 'producto' => 'Pollo', 'temperatura' => 3.5, 'estadoEnvase' => 'correcto'];
    $registroRecepcion = $request('POST', '/api/registros', ['tarea' => $recepcion['@id'], 'establecimiento' => $carniceria['@id'], 'usuario' => $usuario['@id'], 'conforme' => true, 'datos' => $datos], 201);
    expect($request('GET', $registroRecepcion['@id'])['datos'] === $datos, 'El JSON de recepción debe persistirse.');
    $limpieza = $request('POST', '/api/tareas', ['establecimiento' => $restaurante['@id'], 'planControl' => $plan['@id'], 'nombre' => 'Limpieza cámara', 'frecuencia' => 'por_turno', 'configuracion' => ['tipoRespuesta' => 'boolean']], 201);
    $registroLimpieza = $request('POST', '/api/registros', ['tarea' => $limpieza['@id'], 'establecimiento' => $restaurante['@id'], 'usuario' => $usuario['@id'], 'conforme' => true, 'datos' => ['resultado' => true]], 201);
    expect($registroLimpieza['datos']['resultado'] === true, 'El JSON debe admitir respuestas booleanas.');

    $incidentPayload = ['establecimiento' => $restaurante['@id'], 'registro' => $registro['@id'], 'titulo' => 'Temperatura alta', 'descripcion' => 'Cámara fuera de rango', 'gravedad' => 'alta'];
    $violation($request('POST', '/api/incidencias', array_replace($incidentPayload, ['establecimiento' => $carniceria['@id']]), 422), 'registro');
    $incidencia = $request('POST', '/api/incidencias', $incidentPayload, 201);
    expect($incidencia['estado'] === 'abierta', 'La incidencia debe abrirse por defecto.');
    $manual = $request('POST', '/api/incidencias', ['establecimiento' => $carniceria['@id'], 'titulo' => 'Puerta averiada', 'descripcion' => 'La puerta no cierra.', 'gravedad' => 'media'], 201);
    expect(($manual['registro'] ?? null) === null, 'Debe admitirse una incidencia manual.');
    $accion = $request('POST', '/api/acciones-correctivas', ['incidencia' => $incidencia['@id'], 'usuario' => $usuario['@id'], 'descripcion' => 'Trasladar alimentos', 'resultado' => 'Alimentos trasladados a otra cámara'], 201);
    $request('POST', '/api/acciones-correctivas', ['incidencia' => $incidencia['@id'], 'usuario' => $usuario['@id'], 'descripcion' => 'Reparar termostato'], 201);
    $violation($request('PATCH', $incidencia['@id'], ['fechaCierre' => '2000-01-01T00:00:00+00:00'], 422), 'fechaCierre');
    $request('PATCH', $incidencia['@id'], ['estado' => 'resuelta', 'fechaCierre' => (new DateTimeImmutable('+1 minute'))->format(DATE_ATOM)]);

    $before = $request('GET', $registro['@id']);
    $violation($request('PATCH', $plan['@id'], ['establecimiento' => $carniceria['@id']], 422), 'establecimiento');
    $violation($request('PATCH', $punto['@id'], ['establecimiento' => $carniceria['@id']], 422), 'establecimiento');
    $violation($request('PATCH', $tarea['@id'], ['establecimiento' => $carniceria['@id'], 'planControl' => $otroPlan['@id'], 'puntoControl' => null], 422), 'establecimiento');
    $request('PATCH', $tarea['@id'], ['activa' => false, 'limiteMaximo' => '20.000', 'configuracion' => ['tipoRespuesta' => 'boolean']]);
    expect($request('GET', $registro['@id']) === $before, 'Cambiar o desactivar una tarea no debe alterar su histórico.');
    $request('PATCH', $registro['@id'], ['conforme' => true], 405);
    $request('DELETE', $registro['@id'], null, 405);
    $request('PATCH', $accion['@id'], ['descripcion' => 'Modificar evidencia'], 405);
    $request('DELETE', $accion['@id'], null, 405);
    $request('DELETE', $tarea['@id'], null, 405);

    $planActualizado = $request('PATCH', $plan['@id'], ['activo' => false, 'createdAt' => '2000-01-01T00:00:00+00:00', 'updatedAt' => '2000-01-01T00:00:00+00:00']);
    expect($planActualizado['createdAt'] === $plan['createdAt'] && !str_starts_with($planActualizado['updatedAt'], '2000-'), 'Las fechas del plan deben gestionarse automáticamente.');
    $localActualizado = $request('PATCH', $restaurante['@id'], ['activo' => false]);
    expect(!empty($localActualizado['updatedAt']), 'Falta actualizar la fecha del establecimiento.');

    foreach (['entidades-fiscales', 'establecimientos', 'usuarios', 'usuarios-establecimientos', 'planes-control', 'puntos-control', 'plantillas-appcc', 'tareas', 'registros', 'incidencias', 'acciones-correctivas'] as $uri) {
        $request('GET', '/api/'.$uri);
    }
    $persisted = $em->find(TareaAPPCC::class, $tarea['id']);
    expect($persisted->getRegistros()->count() === 1, 'La relación tarea-registros no se ha persistido.');

    // Verificar restricciones reales de BD, omitiendo intencionadamente Validator.
    try {
        $connection->insert('usuario_establecimiento', ['usuario_id' => $usuario['id'], 'establecimiento_id' => $restaurante['id'], 'rol' => 'admin', 'activo' => 1]);
        throw new RuntimeException('La BD permite membresías duplicadas.');
    } catch (UniqueConstraintViolationException) {
    }
    try {
        $connection->delete('tarea_appcc', ['id' => $tarea['id']]);
        throw new RuntimeException('La BD permite borrar una tarea con registros.');
    } catch (ForeignKeyConstraintViolationException) {
    }
    try {
        $connection->delete('entidad_fiscal', ['id' => $fiscal['id']]);
        throw new RuntimeException('La BD permite borrar un titular con establecimientos.');
    } catch (ForeignKeyConstraintViolationException) {
    }
    expect((int) $connection->fetchOne('SELECT COUNT(*) FROM registro_appcc') === 3, 'Se ha perdido un registro histórico.');

    echo "OK: $requestCount peticiones API, modelo multisector, validaciones, relaciones, históricos y restricciones de BD.\n";
} finally {
    $kernel->shutdown();
}
