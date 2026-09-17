<?php

declare(strict_types=1);

namespace App\Tests;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConsultasOpenApiTest extends KernelTestCase
{
    public function testContratoPublicadoConListasCerradasYPaginacion(): void
    {
        self::bootKernel();
        $spec = self::getContainer()->get(OpenApiFactoryInterface::class)();
        self::assertSame('APPCC Demo ES API', $spec->getInfo()->getTitle());
        self::assertSame('0.1.0-mvp', $spec->getInfo()->getVersion());
        $contratos = [
            '/api/registros' => ['tareaProgramada', 'tarea', 'usuario', 'conforme', 'fechaHora[after]', 'fechaHora[before]', 'order[fechaHora]'],
            '/api/incidencias' => ['estado', 'gravedad', 'registro', 'fechaApertura[after]', 'fechaApertura[before]', 'order[fechaApertura]'],
            '/api/acciones-correctivas' => ['incidencia', 'usuario', 'fechaHora[after]', 'fechaHora[before]'],
            '/api/historiales-incidencia' => ['incidencia', 'order[createdAt]'],
            '/api/evidencias' => ['registro', 'incidencia', 'tipo'],
            '/api/tareas' => ['planControl', 'puntoControl', 'frecuencia', 'activa'],
            '/api/planes-control' => ['activo', 'order[nombre]'],
            '/api/puntos-control' => ['activo', 'order[nombre]'],
            '/api/incidencias/{id}/acciones' => ['usuario', 'fechaHora[after]', 'fechaHora[before]'],
            '/api/incidencias/{id}/historial' => ['order[createdAt]'],
            '/api/registros/{id}/evidencias' => ['tipo'],
        ];
        foreach ($contratos as $ruta => $filtros) {
            $path = $spec->getPaths()->getPath($ruta);
            self::assertNotNull($path, $ruta);
            $operacion = $path->getGet();
            self::assertNotNull($operacion, $ruta);
            $query = [];
            $headers = [];
            foreach ($operacion->getParameters() as $p) {
                if ($p->getIn() === 'query') { $query[$p->getName()] = $p; }
                elseif ($p->getIn() === 'header') { $headers[$p->getName()] = $p; }
            }
            self::assertEqualsCanonicalizing([...$filtros, 'page', 'itemsPerPage'], array_keys($query));
            self::assertSame(1, $query['page']->getSchema()['minimum']);
            self::assertSame(1, $query['itemsPerPage']->getSchema()['minimum']);
            self::assertSame(30, $query['itemsPerPage']->getSchema()['default']);
            self::assertSame(100, $query['itemsPerPage']->getSchema()['maximum']);
            self::assertTrue($headers['X-Establecimiento-Id']->getRequired());
            foreach ($query as $name => $p) {
                if (str_starts_with($name, 'order[')) { self::assertSame(['asc', 'desc', 'ASC', 'DESC'], $p->getSchema()['enum']); }
                if (str_ends_with($name, '[after]') || str_ends_with($name, '[before]')) { self::assertSame('date-time', $p->getSchema()['format']); }
            }
            if (str_contains($ruta, '{id}')) {
                self::assertNull($path->getPost()); self::assertNull($path->getPatch()); self::assertNull($path->getDelete());
            }
        }
        $params = $spec->getPaths()->getPath('/api/incidencias')->getGet()->getParameters();
        $enums = [];
        foreach ($params as $p) { $enums[$p->getName()] = $p->getSchema()['enum'] ?? []; }
        self::assertSame(['abierta', 'en_proceso', 'resuelta'], $enums['estado']);
        self::assertSame(['baja', 'media', 'alta', 'critica'], $enums['gravedad']);
    }
}
