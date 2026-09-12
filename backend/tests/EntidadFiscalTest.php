<?php

declare(strict_types=1);

use App\Entity\EntidadFiscal;
use App\Enum\TipoEntidadFiscal;
use App\Kernel;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

// Ejecutar: php tests/EntidadFiscalTest.php. Solo utiliza SQLite en memoria.
require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = '-1';

function check(bool $condition, string $message): void
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
    check($em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform, 'La prueba requiere SQLite.');
    check(($em->getConnection()->getParams()['memory'] ?? false) === true, 'La prueba requiere una base en memoria.');
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    $validator = $container->get('validator');
    $errors = static function (EntidadFiscal $entidad) use ($validator): array {
        $result = [];
        foreach ($validator->validate($entidad) as $violation) {
            $result[$violation->getPropertyPath()] = $violation->getMessage();
        }

        return $result;
    };

    $entidad = (new EntidadFiscal())
        ->setNif('  b12345678  ')
        ->setDireccion('Calle Mayor 1')
        ->setCodigoPostal('28001')
        ->setLocalidad('Madrid')
        ->setProvincia('Madrid');
    check($entidad->getNif() === 'B12345678', 'El NIF debe normalizarse.');
    check($entidad->isActivo() && $entidad->getUpdatedAt() === null, 'Valores iniciales incorrectos.');
    check(isset($errors($entidad)['tipo']), 'El tipo es obligatorio.');

    $entidad->setTipo(TipoEntidadFiscal::EMPRESA)->setRazonSocial('   ');
    check(($errors($entidad)['razonSocial'] ?? '') === 'La razón social es obligatoria para una empresa.', 'Falta validar razón social.');
    $entidad->setRazonSocial('Alimentación Norte S.L.');
    check($errors($entidad) === [], 'Una empresa no requiere nombre ni apellidos.');
    $entidad->setTipo(TipoEntidadFiscal::AUTONOMO)->setRazonSocial(null)->setNombre(' ')->setApellidos(' ');
    check(($errors($entidad)['nombre'] ?? '') === 'El nombre es obligatorio para un autónomo.', 'Falta validar nombre.');
    check(($errors($entidad)['apellidos'] ?? '') === 'Los apellidos son obligatorios para un autónomo.', 'Falta validar apellidos.');
    $entidad->setNombre('Carlos')->setApellidos('Martín Gómez');
    check($errors($entidad) === [], 'Un autónomo no requiere razón social.');

    foreach (['nif', 'direccion', 'codigoPostal', 'localidad', 'provincia'] as $field) {
        $invalid = clone $entidad;
        $invalid->{'set'.ucfirst($field)}('   ');
        check(isset($errors($invalid)[$field]), 'Falta validar el campo obligatorio '.$field);
    }
    foreach (['nombreComercial' => 150, 'razonSocial' => 200, 'nombre' => 100, 'apellidos' => 150, 'nif' => 20, 'direccion' => 255, 'codigoPostal' => 10, 'localidad' => 120, 'provincia' => 120, 'telefono' => 30, 'email' => 180] as $field => $max) {
        $invalid = clone $entidad;
        $invalid->{'set'.ucfirst($field)}(str_repeat('a', $max + 1));
        check(isset($errors($invalid)[$field]), 'Falta validar longitud de '.$field);
    }
    $entidad->setEmail('correo-invalido');
    check(isset($errors($entidad)['email']), 'Falta validar el correo.');
    $entidad->setEmail(null);

    $request = static function (string $method, string $uri, ?array $payload, int $expectedStatus) use ($kernel, $em): array {
        $httpRequest = Request::create($uri, $method, server: [
            'CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], content: $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        $response = $kernel->handle($httpRequest);
        $kernel->terminate($httpRequest, $response);
        $body = $response->getContent() === '' ? [] : json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        check($response->getStatusCode() === $expectedStatus, $method.' '.$uri.': '.$response->getStatusCode().' '.($body['detail'] ?? $body['description'] ?? 'Respuesta inesperada.'));
        $em->clear();

        return $body;
    };

    $payload = [
        'tipo' => 'empresa', 'razonSocial' => 'Empresa de prueba', 'nif' => ' b87654321 ',
        'direccion' => 'Calle Mayor 1', 'codigoPostal' => '28001', 'localidad' => 'Madrid', 'provincia' => 'Madrid',
        'createdAt' => '2000-01-01T00:00:00+00:00', 'updatedAt' => '2000-01-01T00:00:00+00:00',
    ];
    $invalid = $payload;
    unset($invalid['razonSocial']);
    $request('POST', '/api/entidades-fiscales', $invalid, 422);
    $invalid['tipo'] = 'autonomo';
    $request('POST', '/api/entidades-fiscales', $invalid, 422);
    $invalid['tipo'] = 'desconocido';
    $request('POST', '/api/entidades-fiscales', $invalid, 400);

    $created = $request('POST', '/api/entidades-fiscales', $payload, 201);
    check($created['nif'] === 'B87654321' && $created['tipo'] === 'empresa', 'Serialización de NIF/enum incorrecta.');
    check(!str_starts_with($created['createdAt'], '2000-') && ($created['updatedAt'] ?? null) === null, 'Las fechas no deben ser editables por API.');
    $uri = $created['@id'];
    $request('GET', '/api/entidades-fiscales', null, 200);
    $request('GET', $uri, null, 200);
    $request('PATCH', $uri, ['tipo' => 'autonomo'], 422);
    $updated = $request('PATCH', $uri, ['tipo' => 'autonomo', 'nombre' => 'Carlos', 'apellidos' => 'Martín Gómez', 'razonSocial' => null, 'activo' => false], 200);
    check($updated['tipo'] === 'autonomo' && $updated['activo'] === false, 'PATCH no actualiza la entidad.');
    check($updated['createdAt'] === $created['createdAt'] && !empty($updated['updatedAt']), 'Las fechas de actualización son incorrectas.');
    $persisted = $request('GET', $uri, null, 200);
    check($persisted['updatedAt'] === $updated['updatedAt'], 'updatedAt no se ha persistido.');
    $request('DELETE', $uri, null, 204);
    $request('GET', $uri, null, 404);

    $em->persist($entidad);
    $em->flush();
    $duplicate = clone $entidad;
    $em->persist($duplicate);
    try {
        $em->flush();
        throw new RuntimeException('La base de datos permite NIF duplicados.');
    } catch (UniqueConstraintViolationException) {
        // La restricción única debe existir también en la base de datos.
    }

    echo "OK: reglas fiscales, campos, API CRUD, enum, fechas y NIF único.\n";
} finally {
    $kernel->shutdown();
}
