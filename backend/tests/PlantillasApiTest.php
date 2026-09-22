<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\{Establecimiento, PlantillaAPPCC, TareaAPPCC};
use App\Enum\TipoActividad;
use App\Service\{PlantillaAPPCCService, GeneradorTareasProgramadasService, OnboardingService};
use App\Tests\Support\UsuariosApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PlantillasApiTest extends UsuariosApiTestCase
{
    private function plantilla(string $actividad = 'obrador'): PlantillaAPPCC
    {
        self::getContainer()->get(PlantillaAPPCCService::class)->cargarIniciales();
        return $this->em->getRepository(PlantillaAPPCC::class)->findOneBy(['codigo' => $actividad.'-v1']);
    }

    private function uri(PlantillaAPPCC $p): string { return '/api/plantillas-appcc/'.$p->getId().'/aplicar'; }

    public function testCargaReproducibleNoSobrescribeEstadoYPublicaCatalogo(): void
    {
        $service = self::getContainer()->get(PlantillaAPPCCService::class);
        $primera = $service->cargarIniciales();
        self::assertCount(3, $primera);
        $primera[0]->setActiva(false); $this->em->flush();
        $segunda = $service->cargarIniciales();
        self::assertSame(array_map(fn ($p) => $p->getId(), $primera), array_map(fn ($p) => $p->getId(), $segunda));
        self::assertFalse($segunda[0]->isActiva());
        $r = $this->request('GET', '/api/plantillas-appcc', jwt: $this->jwt);
        self::assertSame(200, $r->getStatusCode(), $r->getContent());
        self::assertCount(3, $this->json($r)['member']);
        self::assertSame(401, $this->request('GET', '/api/plantillas-appcc')->getStatusCode());
    }

    #[DataProvider('actividades')]
    public function testAplicacionYRepeticionConReciboCompleto(string $actividad, int $tareas, int $pendientes): void
    {
        $p = $this->plantilla($actividad);
        $this->local->setTipoActividad(TipoActividad::from($actividad)); $this->em->flush();
        $r = $this->api('POST', $this->uri($p), []);
        self::assertSame(200, $r->getStatusCode(), $r->getContent());
        $primera = $this->json($r);
        self::assertFalse($primera['yaAplicada']);
        self::assertSame(['planes' => 6, 'puntos' => 4, 'tareas' => $tareas], $primera['creados']);
        self::assertSame($this->local->getId(), $primera['establecimientoId']);
        self::assertCount($pendientes, array_filter($primera['resultadoInicial']['tareas'], fn ($t) => $t['configuracionPendiente']));
        foreach ($primera['resultadoInicial']['tareas'] as $t) {
            $entity = $this->em->find(TareaAPPCC::class, $t['id']);
            self::assertSame($this->local->getId(), $entity->getEstablecimiento()->getId());
            self::assertSame($this->local->getId(), $entity->getPuntoControl()->getEstablecimiento()->getId());
            self::assertSame($this->local->getId(), $entity->getPlanControl()->getEstablecimiento()->getId());
            if ($t['configuracionPendiente']) {
                self::assertFalse($entity->isActiva());
                self::assertNull($entity->getLimiteMinimo()); self::assertNull($entity->getLimiteMaximo());
            }
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tarea_programada'));
        // Una edición posterior no vuelve a sembrarse ni cambia el recibo original.
        $tarea = $this->em->find(TareaAPPCC::class, $primera['resultadoInicial']['tareas'][0]['id']);
        $tarea->setNombre('Nombre adaptado por el establecimiento'); $this->em->flush();
        $r = $this->api('POST', $this->uri($p), []);
        self::assertSame(200, $r->getStatusCode(), $r->getContent());
        $segunda = $this->json($r);
        self::assertTrue($segunda['yaAplicada']);
        self::assertSame($primera['aplicacionId'], $segunda['aplicacionId']);
        self::assertSame($primera['resultadoInicial'], $segunda['resultadoInicial']);
        self::assertSame(['planes' => 0, 'puntos' => 0, 'tareas' => 0], $segunda['creados']);
        self::assertSame($tareas + 1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tarea_appcc'));
        self::assertSame('Nombre adaptado por el establecimiento', $this->em->find(TareaAPPCC::class, $tarea->getId())->getNombre());
    }
    public static function actividades(): array { return [['restaurante', 7, 2], ['obrador', 7, 2], ['catering', 8, 3]]; }

    #[DataProvider('roles')]
    public function testSoloAdminYResponsable(string $rol, int $status): void
    {
        $p = $this->plantilla();
        $this->em->getConnection()->update('usuario_establecimiento', ['rol' => $rol], ['id' => $this->miembro()]); $this->em->clear();
        $r = $this->api('POST', $this->uri($p), []);
        self::assertSame($status, $r->getStatusCode(), $r->getContent());
        self::assertSame($status === 200 ? 1 : 0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM aplicacion_plantilla_appcc'));
    }
    public static function roles(): array { return [['admin', 200], ['responsable', 200], ['trabajador', 403], ['auditor', 403]]; }

    #[DataProvider('accesos')]
    public function testAutenticacionCabeceraMembresiaYActividad(string $caso, int $status): void
    {
        $p = $this->plantilla(); $db = $this->em->getConnection();
        if ($caso === 'membresiaInactiva') { $db->update('usuario_establecimiento', ['activo' => false], ['id' => $this->miembro()], ['boolean', 'integer']); }
        if ($caso === 'localInactivo') { $this->local->setActivo(false); $this->em->flush(); }
        if ($caso === 'fiscalInactiva') { $this->local->getEntidadFiscal()->setActivo(false); $this->em->flush(); }
        if ($caso === 'plantillaInactiva') { $p->setActiva(false); $this->em->flush(); }
        if ($caso === 'incompatible') { $this->local->setTipoActividad(TipoActividad::CATERING); $this->em->flush(); }
        $this->em->clear();
        $r = $this->request('POST', $caso === 'inexistente' ? '/api/plantillas-appcc/999999/aplicar' : $this->uri($p), [],
            $caso === 'anonimo' ? null : $this->jwt, $caso === 'sinCabecera' ? null : ($caso === 'ajeno' ? $this->otroLocal->getId() : ($caso === 'cabeceraInvalida' ? 0 : $this->local->getId())));
        self::assertSame($status, $r->getStatusCode(), $r->getContent());
        self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM aplicacion_plantilla_appcc'));
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM tarea_appcc'));
    }
    public static function accesos(): array
    {
        return [['anonimo', 401], ['sinCabecera', 400], ['cabeceraInvalida', 400], ['ajeno', 403], ['membresiaInactiva', 403],
            ['localInactivo', 403], ['fiscalInactiva', 403], ['plantillaInactiva', 422], ['incompatible', 422], ['inexistente', 404]];
    }

    public function testNoAceptaLocalEnCuerpoYAislaAplicaciones(): void
    {
        $p = $this->plantilla();
        self::assertSame(400, $this->api('POST', $this->uri($p), ['establecimiento' => '/api/establecimientos/'.$this->otroLocal->getId()])->getStatusCode());
        $a = $this->api('POST', $this->uri($p), []); self::assertSame(200, $a->getStatusCode(), $a->getContent());
        $b = $this->request('POST', $this->uri($p), [], $this->otroJwt, $this->otroLocal->getId()); self::assertSame(200, $b->getStatusCode(), $b->getContent());
        foreach (['planes', 'puntos', 'tareas'] as $key) {
            self::assertSame([], array_intersect(array_column($this->json($a)['resultadoInicial'][$key], 'id'), array_column($this->json($b)['resultadoInicial'][$key], 'id')));
            foreach ($this->json($b)['resultadoInicial'][$key] as $item) { self::assertSame(404, $this->api('GET', $item['iri'])->getStatusCode()); }
        }
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM aplicacion_plantilla_appcc'));
    }

    public function testPlantillaInvalidaRevierteTodosLosRecursos(): void
    {
        $p = $this->plantilla(); $config = $p->getConfiguracion();
        $config['planes'][5]['tareas'][0]['frecuencia'] = 'inexistente'; $p->setConfiguracion($config); $this->em->flush();
        self::assertSame(422, $this->api('POST', $this->uri($p), [])->getStatusCode());
        foreach (['punto_control', 'aplicacion_plantilla_appcc'] as $tabla) { self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM '.$tabla)); }
        foreach (['plan_control', 'tarea_appcc'] as $tabla) { self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM '.$tabla)); }
    }

    public function testNoConfundeColisionDeNombresConAplicacionPrevia(): void
    {
        $p = $this->plantilla();
        $this->tarea->getPlanControl()->setNombre($p->getConfiguracion()['planes'][0]['nombre']); $this->em->flush();
        self::assertSame(422, $this->api('POST', $this->uri($p), [])->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM aplicacion_plantilla_appcc'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM punto_control'));
    }

    public function testControlPendienteNoSeActivaNiProgramaHastaConfigurarLimites(): void
    {
        $p = $this->plantilla();
        $r = $this->api('POST', $this->uri($p), []); self::assertSame(200, $r->getStatusCode(), $r->getContent());
        $t = $this->json($r)['resultadoInicial']['tareas'][0];
        $r = $this->api('PATCH', $t['iri'], ['activa' => true, 'requiereLimites' => false, 'configuracionPendiente' => false, 'configuracion' => ['tipoRespuesta' => 'boolean']]);
        self::assertSame(422, $r->getStatusCode(), $r->getContent());
        self::assertTrue($this->em->find(TareaAPPCC::class, $t['id'])->isRequiereLimites());
        self::assertFalse($this->em->find(TareaAPPCC::class, $t['id'])->isActiva());
        $r = self::getContainer()->get(GeneradorTareasProgramadasService::class)->generar(new \DateTimeImmutable('2026-09-12'), new \DateTimeImmutable('2026-09-13'));
        self::assertGreaterThan(0, $r->creadas);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tarea_programada WHERE tarea_id = ?', [$t['id']]));
        // Valores exclusivos del fixture, no límites recomendados del catálogo.
        $r = $this->api('PATCH', $t['iri'], ['limiteMinimo' => '1', 'limiteMaximo' => '4', 'unidad' => '°C', 'instrucciones' => 'Procedimiento validado del equipo de prueba.', 'activa' => true]);
        self::assertSame(200, $r->getStatusCode(), $r->getContent());
        self::assertFalse($this->json($r)['configuracionPendiente'], $r->getContent());
        $r = $this->api('PATCH', $t['iri'], ['limiteMinimo' => null, 'limiteMaximo' => null]);
        self::assertSame(422, $r->getStatusCode(), $r->getContent());
    }

    public function testOnboardingConPlantillaSigueSiendoAtomicoEIdempotente(): void
    {
        $p = $this->plantilla();
        $nuevo = self::getContainer()->get(OnboardingService::class)->crearOnboarding($this->fiscal('B11111111'), $this->establecimiento('Nuevo obrador'), $this->usuario, $p);
        $resultado = self::getContainer()->get(PlantillaAPPCCService::class)->aplicar($p, $nuevo);
        self::assertTrue($resultado['yaAplicada']);
        self::assertCount(7, $resultado['aplicacion']->getResultado()['tareas']);
        self::assertSame(7, $this->em->getRepository(TareaAPPCC::class)->count(['establecimiento' => $nuevo]));
    }

    public function testControlPendienteBajoDemandaNoPuedeProgramarsePorApi(): void
    {
        $p = $this->plantilla();
        $r = $this->api('POST', $this->uri($p), []); self::assertSame(200, $r->getStatusCode(), $r->getContent());
        $t = $this->json($r)['resultadoInicial']['tareas'][1];
        $r = $this->api('POST', $t['iri'].'/programaciones', ['fechaProgramada' => '2026-09-12T10:00:00Z']);
        self::assertSame(422, $r->getStatusCode(), $r->getContent());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tarea_programada'));
    }

    public function testOpenApiDescribeOperacionYCabecera(): void
    {
        $spec = self::getContainer()->get(\ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface::class)();
        $op = $spec->getPaths()->getPath('/api/plantillas-appcc/{id}/aplicar')->getPost();
        self::assertArrayHasKey('200', $op->getResponses());
        $headers = array_filter($op->getParameters(), fn ($p) => $p->getName() === 'X-Establecimiento-Id');
        self::assertCount(1, $headers); self::assertTrue(array_values($headers)[0]->getRequired());
    }
}
