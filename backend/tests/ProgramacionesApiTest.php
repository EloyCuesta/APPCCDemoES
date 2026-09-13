<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\{Establecimiento, PlanControl, TareaAPPCC, TareaProgramada, Usuario, UsuarioEstablecimiento};
use App\Enum\{FrecuenciaTarea, RolEstablecimiento, TipoPlanControl};
use App\Service\TareaProgramadaService;
use App\Tests\Support\PostgresTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\{Request, Response};

final class ProgramacionesApiTest extends PostgresTestCase
{
    private string $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tarea->setFrecuencia(FrecuenciaTarea::BAJO_DEMANDA);
        $this->usuario->setPassword(self::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class)->hashPassword($this->usuario, bin2hex(random_bytes(16))));
        $this->em->flush();
        $this->jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->usuario);
    }

    private function api(string $method, string $uri, ?array $data = null, bool $jwt = true, bool $header = true): Response
    {
        self::getContainer()->get('security.token_storage')->setToken(null);
        $server = ['CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];
        if ($jwt) { $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->jwt; }
        if ($header) { $server['HTTP_X_ESTABLECIMIENTO_ID'] = (string) $this->local->getId(); }
        $request = Request::create($uri, $method, server: $server, content: $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR));
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        if (!$this->em->isOpen()) { self::getContainer()->get('doctrine')->resetManager(); $this->em = self::getContainer()->get('doctrine')->getManager(); }
        $this->em->clear();
        return $response;
    }

    private function json(Response $r, int $status = 200): array
    {
        self::assertSame($status, $r->getStatusCode(), $r->getContent());
        return json_decode($r->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function crear(array $extra = []): Response
    {
        return $this->api('POST', '/api/tareas/'.$this->tarea->getId().'/programaciones', $extra + ['fechaProgramada' => '2026-09-12T11:00:00+02:00']);
    }

    private function rol(RolEstablecimiento $rol): void
    {
        $m = $this->em->getRepository(UsuarioEstablecimiento::class)->findOneBy(['usuario' => $this->usuario->getId(), 'establecimiento' => $this->local->getId()]);
        $m->setRol($rol); $this->em->flush();
    }

    public function testCreacionManualUtcYConflictoIdempotente(): void
    {
        $r = $this->crear(['fechaLimite' => '2026-09-12T12:00:00+02:00', 'asignadoA' => '/api/usuarios/'.$this->usuario->getId()]);
        $data = $this->json($r, 201);
        self::assertStringContainsString('/api/tareas-programadas/', $data['@id']);
        self::assertSame('pendiente', $data['estado']);
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM tarea_programada WHERE id = ?', [$data['id']]);
        self::assertSame('2026-09-12 09:00:00', $row['fecha_programada']);
        self::assertSame('2026-09-12 10:00:00', $row['fecha_limite']);
        self::assertSame($this->usuario->getId(), $row['asignado_a_id']);
        self::assertSame(409, $this->crear(['fechaProgramada' => '2026-09-12T09:00:00Z'])->getStatusCode());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tarea_programada'));
        self::assertSame(200, $this->api('GET', $data['@id'])->getStatusCode());
    }

    #[DataProvider('frecuenciasAutomaticas')]
    public function testRechazaCreacionRecurrente(FrecuenciaTarea $frecuencia): void
    {
        $this->tarea->setFrecuencia($frecuencia)->setDiaSemana($frecuencia === FrecuenciaTarea::SEMANAL ? 1 : null)->setDiaMes($frecuencia === FrecuenciaTarea::MENSUAL ? 14 : null);
        $this->em->flush();
        self::assertSame(422, $this->crear()->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tarea_programada'));
    }

    public static function frecuenciasAutomaticas(): array { return array_map(static fn ($f) => [$f], [FrecuenciaTarea::DIARIA, FrecuenciaTarea::SEMANAL, FrecuenciaTarea::MENSUAL, FrecuenciaTarea::POR_TURNO, FrecuenciaTarea::POR_RECEPCION]); }

    #[DataProvider('fechasInvalidas')]
    public function testRechazaFechasInvalidas(array $data): void { self::assertSame(422, $this->crear($data)->getStatusCode()); }
    public static function fechasInvalidas(): array
    {
        return [[['fechaProgramada' => 'tomorrow']], [['fechaProgramada' => '2026-02-30T10:00:00Z']], [['fechaProgramada' => '2026-09-12T10:00:00']], [['fechaLimite' => '2026-09-12T08:59:59Z']]];
    }

    public function testLimiteIgualYAsignacionOpcional(): void
    {
        $data = $this->json($this->crear(['fechaLimite' => '2026-09-12T09:00:00Z']), 201);
        self::assertSame($data['fechaProgramada'], $data['fechaLimite']);
        self::assertNull($this->em->find(TareaProgramada::class, $data['id'])->getAsignadoA());
    }

    #[DataProvider('contextosInactivos')]
    public function testRechazaTareaOPlanInactivo(string $campo): void
    {
        if ($campo === 'tarea') { $this->tarea->setActiva(false); }
        else { $this->tarea->getPlanControl()->setActivo(false); }
        $this->em->flush();
        self::assertSame(422, $this->crear()->getStatusCode());
    }
    public static function contextosInactivos(): array { return [['tarea'], ['plan']]; }

    public function testAsignacionDesasignacionYPendienteVencida(): void
    {
        $p = $this->json($this->crear(['fechaLimite' => '2026-09-12T09:30:00Z']), 201);
        $uri = $p['@id'].'/asignar';
        $data = $this->json($this->api('POST', $uri, ['usuario' => '/api/usuarios/'.$this->usuario->getId()]));
        self::assertSame('/api/usuarios/'.$this->usuario->getId(), $data['asignadoA']);
        self::getContainer()->get(TareaProgramadaService::class)->detectarVencidas($this->em->find(Establecimiento::class, $this->local->getId()));
        $data = $this->json($this->api('POST', $uri, ['usuario' => null]));
        self::assertSame('vencida', $data['estado']);
        self::assertNull($this->em->find(TareaProgramada::class, $p['id'])->getAsignadoA());
        self::assertSame(422, $this->api('POST', $uri, [])->getStatusCode());
    }

    #[DataProvider('usuariosInvalidos')]
    public function testRechazaAsignadoSinMembresiaActiva(string $tipo): void
    {
        $usuarioId = $this->otroUsuario->getId();
        if ($tipo !== 'ajeno') {
            $m = (new UsuarioEstablecimiento())->setUsuario($this->otroUsuario)->setEstablecimiento($this->local)->setRol(RolEstablecimiento::TRABAJADOR)->setActivo($tipo !== 'membresia');
            $this->em->persist($m);
            if ($tipo === 'usuario') { $this->otroUsuario->setActivo(false); }
            $this->em->flush();
        }
        $p = $this->json($this->crear(), 201);
        self::assertSame(422, $this->api('POST', $p['@id'].'/asignar', ['usuario' => '/api/usuarios/'.$usuarioId])->getStatusCode());
        self::assertSame(422, $this->crear(['fechaProgramada' => '2026-09-12T08:00:00Z', 'asignadoA' => '/api/usuarios/'.$usuarioId])->getStatusCode());
        self::assertNull($this->em->find(TareaProgramada::class, $p['id'])->getAsignadoA());
    }
    public static function usuariosInvalidos(): array { return [['ajeno'], ['membresia'], ['usuario']]; }

    public function testOmisionAuditadaSinSuplantacionYCierreDefinitivo(): void
    {
        $p = $this->json($this->crear(), 201);
        $data = $this->json($this->api('POST', $p['@id'].'/omitir', ['motivo' => '  Establecimiento cerrado.  ', 'omitidaPor' => '/api/usuarios/'.$this->otroUsuario->getId(), 'omitidaAt' => '2000-01-01T00:00:00Z', 'estado' => 'completada']));
        self::assertSame('omitida', $data['estado']);
        self::assertSame('Establecimiento cerrado.', $data['motivoOmision']);
        self::assertSame('/api/usuarios/'.$this->usuario->getId(), $data['omitidaPor']);
        self::assertSame($this->clock->now()->getTimestamp(), (new \DateTimeImmutable($data['omitidaAt']))->getTimestamp());
        self::assertSame(422, $this->api('POST', $p['@id'].'/omitir', ['motivo' => 'Otra omisión'])->getStatusCode());
        self::assertSame(422, $this->api('POST', $p['@id'].'/asignar', ['usuario' => null])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/registros', ['tareaProgramada' => $p['@id'], 'establecimiento' => '/api/establecimientos/'.$this->local->getId(), 'valorNumerico' => '3'])->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM registro_appcc'));
        self::assertContains($this->api('PATCH', $p['@id'], ['estado' => 'pendiente'])->getStatusCode(), [404, 405]);
    }

    public function testOmisionVencidaYRechazoCompletada(): void
    {
        $vencida = $this->json($this->crear(['fechaLimite' => '2026-09-12T09:30:00Z']), 201);
        self::getContainer()->get(TareaProgramadaService::class)->detectarVencidas($this->em->find(Establecimiento::class, $this->local->getId()));
        self::assertSame(200, $this->api('POST', $vencida['@id'].'/omitir', ['motivo' => 'Cierre del local'])->getStatusCode());
        $completada = $this->json($this->crear(['fechaProgramada' => '2026-09-12T09:10:00Z']), 201);
        self::assertSame(201, $this->api('POST', '/api/registros', ['tareaProgramada' => $completada['@id'], 'establecimiento' => '/api/establecimientos/'.$this->local->getId(), 'valorNumerico' => '3'])->getStatusCode());
        self::assertSame(422, $this->api('POST', $completada['@id'].'/omitir', ['motivo' => 'Cierre del local'])->getStatusCode());
        self::assertSame(422, $this->api('POST', $completada['@id'].'/asignar', ['usuario' => null])->getStatusCode());
    }

    #[DataProvider('motivosInvalidos')]
    public function testMotivoObligatorioYAcotado(string $motivo): void
    {
        $p = $this->json($this->crear(), 201);
        self::assertSame(422, $this->api('POST', $p['@id'].'/omitir', ['motivo' => $motivo])->getStatusCode());
        self::assertSame('pendiente', $this->em->find(TareaProgramada::class, $p['id'])->getEstado()->value);
    }
    public static function motivosInvalidos(): array { return [[''], ['   '], ['ab'], [str_repeat('a', 2001)]]; }

    #[DataProvider('roles')]
    public function testPermisosPorRol(RolEstablecimiento $rol, int $esperado): void
    {
        $p = $this->json($this->crear(), 201);
        $this->rol($rol);
        self::assertSame($esperado === 200 ? 201 : $esperado, $this->crear(['fechaProgramada' => '2026-09-12T08:00:00Z'])->getStatusCode());
        self::assertSame($esperado, $this->api('POST', $p['@id'].'/asignar', ['usuario' => null])->getStatusCode());
        self::assertSame($esperado, $this->api('POST', $p['@id'].'/omitir', ['motivo' => 'Cierre del local'])->getStatusCode());
        self::assertSame(200, $this->api('GET', '/api/tareas-programadas/agenda')->getStatusCode());
    }
    public static function roles(): array { return [[RolEstablecimiento::ADMIN, 200], [RolEstablecimiento::RESPONSABLE, 200], [RolEstablecimiento::TRABAJADOR, 403], [RolEstablecimiento::AUDITOR, 403]]; }

    public function testJwtCabeceraYMembresiaObligatorios(): void
    {
        $p = $this->json($this->crear(), 201);
        foreach ([['GET', '/api/tareas-programadas', null], ['POST', '/api/tareas/'.$this->tarea->getId().'/programaciones', ['fechaProgramada' => '2026-09-12T08:00:00Z']], ['POST', $p['@id'].'/asignar', ['usuario' => null]], ['POST', $p['@id'].'/omitir', ['motivo' => 'Cierre del local']]] as [$metodo, $uri, $body]) {
            self::assertSame(401, $this->api($metodo, $uri, $body, jwt: false)->getStatusCode());
            self::assertSame(400, $this->api($metodo, $uri, $body, header: false)->getStatusCode());
        }
        $m = $this->em->getRepository(UsuarioEstablecimiento::class)->findOneBy(['usuario' => $this->usuario->getId(), 'establecimiento' => $this->local->getId()]);
        $m->setActivo(false); $this->em->flush();
        self::assertSame(403, $this->crear()->getStatusCode());
        self::assertSame(403, $this->api('GET', '/api/tareas-programadas/agenda')->getStatusCode());
    }

    private function ajena(): TareaProgramada
    {
        $local = $this->em->find(Establecimiento::class, $this->otroLocal->getId());
        $plan = (new PlanControl())->setEstablecimiento($local)->setNombre('Plan ajeno')->setTipo(TipoPlanControl::TEMPERATURAS);
        $tarea = (new TareaAPPCC())->setEstablecimiento($local)->setPlanControl($plan)->setNombre('Tarea ajena')->setFrecuencia(FrecuenciaTarea::BAJO_DEMANDA);
        $this->em->persist($plan); $this->em->persist($tarea); $this->em->flush();
        return self::getContainer()->get(TareaProgramadaService::class)->programar($tarea, $local, $this->clock->now());
    }

    public function testRecursosYFiltrosAjenosNuncaCruzanTenant(): void
    {
        $ajena = $this->ajena(); $id = $ajena->getId(); $tareaId = $ajena->getTarea()->getId();
        self::assertSame(404, $this->api('POST', '/api/tareas/'.$tareaId.'/programaciones', ['fechaProgramada' => '2026-09-12T10:00:00Z'])->getStatusCode());
        foreach (['asignar' => ['usuario' => null], 'omitir' => ['motivo' => 'Cierre del local']] as $accion => $body) {
            self::assertSame(404, $this->api('POST', '/api/tareas-programadas/'.$id.'/'.$accion, $body)->getStatusCode());
        }
        self::assertSame(404, $this->api('GET', '/api/tareas-programadas/'.$id)->getStatusCode());
        // El usuario también pertenece al otro tenant: ni siquiera una IRI resoluble amplía el ámbito seleccionado.
        $m = (new UsuarioEstablecimiento())->setUsuario($this->em->find(Usuario::class, $this->usuario->getId()))->setEstablecimiento($this->em->find(Establecimiento::class, $this->otroLocal->getId()))->setRol(RolEstablecimiento::ADMIN);
        $this->em->persist($m); $this->em->flush();
        $r = $this->api('GET', '/api/tareas-programadas?'.http_build_query(['tarea' => '/api/tareas/'.$tareaId]));
        self::assertContains($r->getStatusCode(), [200, 404]);
        if ($r->getStatusCode() === 200) { self::assertSame([], $this->json($r)['member']); }
        self::assertSame([], $this->json($this->api('GET', '/api/tareas-programadas/agenda'))['member']);
    }

    public function testFiltrosSqlFechasConOffsetOrdenYPaginacion(): void
    {
        $ids = [];
        foreach (['08', '09', '10', '11'] as $hora) {
            $data = $this->json($this->crear(['fechaProgramada' => '2026-09-12T'.$hora.':00:00Z', 'fechaLimite' => $hora === '08' ? '2026-09-12T08:30:00Z' : null, 'asignadoA' => $hora === '09' ? '/api/usuarios/'.$this->usuario->getId() : null]), 201);
            $ids[] = $data['id'];
        }
        self::getContainer()->get(TareaProgramadaService::class)->detectarVencidas($this->em->find(Establecimiento::class, $this->local->getId()));
        self::assertSame(200, $this->api('POST', '/api/tareas-programadas/'.$ids[3].'/omitir', ['motivo' => 'Cierre del local'])->getStatusCode());
        $this->ajena();
        $casos = [
            [['estado' => 'vencida'], [$ids[0]]],
            [['estado' => 'pendiente'], [$ids[1], $ids[2]]],
            [['fechaProgramada' => ['after' => '2026-09-12T11:00:00+02:00', 'before' => '2026-09-12T12:00:00+02:00']], [$ids[1], $ids[2]]],
            [['fechaLimite' => ['before' => '2026-09-12T08:30:00Z']], [$ids[0]]],
            [['asignadoA' => '/api/usuarios/'.$this->usuario->getId()], [$ids[1]]],
            [['tarea' => '/api/tareas/'.$this->tarea->getId()], $ids],
            [['order' => ['fechaProgramada' => 'desc']], array_reverse($ids)],
        ];
        foreach ($casos as [$filtros, $esperados]) {
            $data = $this->json($this->api('GET', '/api/tareas-programadas?'.http_build_query($filtros)));
            self::assertSame($esperados, array_column($data['member'], 'id'), json_encode($filtros));
            self::assertSame(count($esperados), $data['totalItems']);
        }
        $pagina = $this->json($this->api('GET', '/api/tareas-programadas?itemsPerPage=2&page=2'));
        self::assertSame([$ids[2], $ids[3]], array_column($pagina['member'], 'id'));
        self::assertSame(4, $pagina['totalItems']);
        $agenda = $this->json($this->api('GET', '/api/tareas-programadas/agenda'));
        self::assertSame(array_slice($ids, 0, 3), array_column($agenda['member'], 'id'));
        self::assertSame(400, $this->api('GET', '/api/tareas-programadas?fechaProgramada[after]=tomorrow')->getStatusCode());
    }

    public function testPuedeRetirarUnaAsignacionCuyaMembresiaYaFueDesactivada(): void
    {
        $m = (new UsuarioEstablecimiento())->setUsuario($this->otroUsuario)->setEstablecimiento($this->local)->setRol(RolEstablecimiento::TRABAJADOR);
        $this->em->persist($m); $this->em->flush(); $mId = $m->getId();
        $a = $this->json($this->crear(['asignadoA' => '/api/usuarios/'.$this->otroUsuario->getId()]), 201);
        $b = $this->json($this->crear(['fechaProgramada' => '2026-09-12T08:00:00Z', 'asignadoA' => '/api/usuarios/'.$this->otroUsuario->getId()]), 201);
        $this->em->find(UsuarioEstablecimiento::class, $mId)->setActivo(false); $this->em->flush();
        self::assertSame(200, $this->api('POST', $a['@id'].'/asignar', ['usuario' => null])->getStatusCode());
        self::assertSame(200, $this->api('POST', $b['@id'].'/omitir', ['motivo' => 'Cierre del local'])->getStatusCode());
    }

    #[DataProvider('identificadoresInvalidos')]
    public function testIdentificadoresNoSeTruncanNiProducenErroresSql(string $id): void
    {
        self::assertSame(404, $this->api('POST', '/api/tareas/'.$id.'/programaciones', ['fechaProgramada' => '2026-09-12T10:00:00Z'])->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/tareas-programadas/'.$id.'/asignar', ['usuario' => null])->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/tareas-programadas/'.$id.'/omitir', ['motivo' => 'Cierre del local'])->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tarea_programada'));
    }
    public static function identificadoresInvalidos(): array { return [['1abc'], ['0'], ['2147483648'], ['9999999999999999999999999']]; }
}
