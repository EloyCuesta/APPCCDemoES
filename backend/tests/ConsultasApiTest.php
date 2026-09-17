<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\{AccionCorrectiva, Establecimiento, Evidencia, HistorialIncidencia, Incidencia, PlanControl, PuntoControl, RegistroAPPCC, TareaAPPCC, TareaProgramada, Usuario, UsuarioEstablecimiento};
use App\Enum\{EstadoIncidencia, FrecuenciaTarea, GravedadIncidencia, RolEstablecimiento, TipoEvidencia, TipoPlanControl, TipoPuntoControl};
use App\Tests\Support\UsuariosApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConsultasApiTest extends UsuariosApiTestCase
{
    private array $datos = [];
    private array $ajenos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->datos = [];
        // El autor alternativo también pertenece al tenant; se prueba por separado la revocación.
        $this->em->persist((new UsuarioEstablecimiento())->setUsuario($this->otroUsuario)->setEstablecimiento($this->local)->setRol(RolEstablecimiento::TRABAJADOR));
        $this->datos[] = $this->fila($this->local, $this->usuario, 'Zulu', '08', true);
        $this->datos[] = $this->fila($this->local, $this->otroUsuario, 'Alfa', '09', false);
        $this->datos[] = $this->fila($this->local, $this->usuario, 'Alfa', '09', true);
        $this->ajenos = $this->fila($this->otroLocal, $this->otroUsuario, 'Ajeno', '10', false);
        $this->em->flush();
    }

    /** Fixtures de lectura persistidas en PostgreSQL, incluidas relaciones, fechas y estados distintos. */
    private function fila(Establecimiento $local, Usuario $usuario, string $nombre, string $hora, bool $activo): array
    {
        $fecha = new \DateTimeImmutable('2026-09-12T'.$hora.':00:00Z');
        $plan = (new PlanControl())->setEstablecimiento($local)->setNombre($nombre)->setTipo(TipoPlanControl::TEMPERATURAS)->setActivo($activo);
        $punto = (new PuntoControl())->setEstablecimiento($local)->setNombre($nombre)->setTipo(TipoPuntoControl::CAMARA_FRIGORIFICA)->setActivo($activo);
        $tarea = (new TareaAPPCC())->setEstablecimiento($local)->setPlanControl($plan)->setPuntoControl($punto)->setNombre($nombre)
            ->setFrecuencia($activo ? FrecuenciaTarea::BAJO_DEMANDA : FrecuenciaTarea::POR_RECEPCION)->setActiva($activo);
        $programada = (new TareaProgramada())->setEstablecimiento($local)->setTarea($tarea)->setFechaProgramada($fecha);
        $registro = (new RegistroAPPCC())->setEstablecimiento($local)->setTareaProgramada($programada)->setUsuario($usuario)->setFechaHora($fecha)->setConforme($activo);
        $programada->completar($fecha);
        $programada->setRegistro($registro);
        $incidencia = (new Incidencia())->setEstablecimiento($local)->setRegistro($registro)->setTitulo($nombre)->setDescripcion('Incidencia de prueba')
            ->setGravedad($activo ? GravedadIncidencia::BAJA : GravedadIncidencia::ALTA)
            ->setEstado($activo ? EstadoIncidencia::ABIERTA : EstadoIncidencia::EN_PROCESO)->setFechaApertura($fecha);
        $accion = (new AccionCorrectiva())->setIncidencia($incidencia)->setUsuario($usuario)->setDescripcion('Acción de prueba')->setFechaHora($fecha);
        $historial = new HistorialIncidencia($incidencia, null, $incidencia->getEstado(), $usuario, createdAt: $fecha);
        $evidencia = (new Evidencia())->setRegistro($registro)->setSubidaPor($usuario)->setTipo($activo ? TipoEvidencia::FOTO : TipoEvidencia::DOCUMENTO)
            ->setStorageKey('definitivo/'.bin2hex(random_bytes(32)))->setNombreOriginal($activo ? 'foto.png' : 'documento.pdf')
            ->setMimeType($activo ? 'image/png' : 'application/pdf')->setTamanoBytes(50)->setHashSha256(str_repeat('a', 64));
        $fila = compact('plan', 'punto', 'tarea', 'programada', 'registro', 'incidencia', 'accion', 'historial', 'evidencia');
        foreach ($fila as $objeto) { $this->em->persist($objeto); }
        return $fila;
    }

    private function coleccion(string $ruta, array $params = []): array
    {
        $r = $this->api('GET', '/api/'.$ruta.($params === [] ? '' : '?'.http_build_query($params)));
        self::assertSame(200, $r->getStatusCode(), $r->getContent());
        return $this->json($r);
    }

    private function comprobar(string $ruta, array $params, array $esperados, ?int $total = null): array
    {
        $data = $this->coleccion($ruta, $params);
        self::assertSame(array_map(static fn ($e) => is_object($e) ? $e->getId() : $e, $esperados), array_column($data['member'], 'id'));
        self::assertSame($total ?? count($esperados), $data['totalItems']);
        return $data;
    }

    public function testRegistrosTodosLosFiltrosYCombinaciones(): void
    {
        [$a, $b, $c] = $this->datos;
        $this->comprobar('registros', [], [$b['registro'], $c['registro'], $a['registro']]);
        $this->comprobar('registros', ['tareaProgramada' => '/api/tareas-programadas/'.$b['programada']->getId()], [$b['registro']]);
        $this->comprobar('registros', ['tarea' => '/api/tareas/'.$a['tarea']->getId()], [$a['registro']]);
        $this->comprobar('registros', ['usuario' => '/api/usuarios/'.$this->usuario->getId()], [$c['registro'], $a['registro']]);
        foreach (['false', '0'] as $valor) { $this->comprobar('registros', ['conforme' => $valor], [$b['registro']]); }
        foreach (['true', '1'] as $valor) { $this->comprobar('registros', ['conforme' => $valor, 'order' => ['fechaHora' => 'asc']], [$a['registro'], $c['registro']]); }
        $this->comprobar('registros', ['conforme' => 'true', 'usuario' => '/api/usuarios/'.$this->usuario->getId(),
            'fechaHora' => ['after' => '2026-09-12T11:00:00+02:00', 'before' => '2026-09-12T09:00:00Z']], [$c['registro']]);
        $this->comprobar('registros', ['tarea' => '/api/tareas/'.$a['tarea']->getId(), 'tareaProgramada' => '/api/tareas-programadas/'.$b['programada']->getId()], []);
        $this->comprobar('registros', ['fechaHora' => ['after' => '2026-09-12T10:00:00Z', 'before' => '2026-09-12T08:00:00Z']], []);
        $data = $this->comprobar('registros', ['conforme' => 'true', 'order' => ['fechaHora' => 'ASC'], 'itemsPerPage' => 1, 'page' => 2], [$c['registro']], 2);
        self::assertArrayHasKey('previous', $data['view']);
    }

    public function testIncidenciasFiltrosOrdenFechasYPagina(): void
    {
        [$a, $b, $c] = $this->datos;
        $this->comprobar('incidencias', ['estado' => 'abierta', 'gravedad' => 'baja', 'order' => ['fechaApertura' => 'asc']], [$a['incidencia'], $c['incidencia']]);
        $this->comprobar('incidencias', ['estado' => 'en_proceso', 'gravedad' => 'alta', 'registro' => '/api/registros/'.$b['registro']->getId()], [$b['incidencia']]);
        $this->comprobar('incidencias', ['estado' => 'resuelta'], []);
        $this->comprobar('incidencias', ['fechaApertura' => ['after' => '2026-09-12T11:00:00+02:00', 'before' => '2026-09-12T09:00:00Z'], 'order' => ['fechaApertura' => 'desc'], 'itemsPerPage' => 1, 'page' => 2], [$c['incidencia']], 2);
    }

    public function testAccionesHistorialYEvidencias(): void
    {
        [$a, $b, $c] = $this->datos;
        $this->comprobar('acciones-correctivas', ['incidencia' => '/api/incidencias/'.$b['incidencia']->getId(), 'usuario' => '/api/usuarios/'.$this->otroUsuario->getId(),
            'fechaHora' => ['after' => '2026-09-12T11:00:00+02:00', 'before' => '2026-09-12T09:00:00Z']], [$b['accion']]);
        $this->comprobar('acciones-correctivas', ['usuario' => '/api/usuarios/'.$this->usuario->getId(), 'itemsPerPage' => 1, 'page' => 2], [$c['accion']], 2);
        $this->comprobar('historiales-incidencia', ['incidencia' => '/api/incidencias/'.$a['incidencia']->getId()], [$a['historial']]);
        $this->comprobar('historiales-incidencia', ['order' => ['createdAt' => 'DESC'], 'itemsPerPage' => 1, 'page' => 2], [$c['historial']], 3);
        $this->comprobar('evidencias', ['registro' => '/api/registros/'.$b['registro']->getId(), 'tipo' => 'documento'], [$b['evidencia']]);
        $this->comprobar('evidencias', ['tipo' => 'foto', 'itemsPerPage' => 1, 'page' => 2], [$c['evidencia']], 2);
        $this->comprobar('evidencias', ['tipo' => 'firma'], []);
        $e = (new Evidencia())->setIncidencia($this->em->find(Incidencia::class, $a['incidencia']->getId()))
            ->setSubidaPor($this->em->find(Usuario::class, $this->usuario->getId()))->setTipo(TipoEvidencia::DOCUMENTO)
            ->setStorageKey('definitivo/'.bin2hex(random_bytes(32)))->setNombreOriginal('informe.pdf')->setMimeType('application/pdf')->setTamanoBytes(20);
        $this->em->persist($e); $this->em->flush();
        $this->comprobar('evidencias', ['incidencia' => '/api/incidencias/'.$a['incidencia']->getId(), 'tipo' => 'documento'], [$e]);
        $this->comprobar('evidencias', ['incidencia' => '/api/incidencias/'.$a['incidencia']->getId(), 'registro' => '/api/registros/'.$a['registro']->getId()], []);
    }

    public function testTareasPlanesYPuntos(): void
    {
        [$a, $b, $c] = $this->datos;
        $this->comprobar('tareas', ['planControl' => '/api/planes-control/'.$a['plan']->getId(), 'puntoControl' => '/api/puntos-control/'.$a['punto']->getId(),
            'frecuencia' => 'bajo_demanda', 'activa' => 'true'], [$a['tarea']]);
        $this->comprobar('tareas', ['frecuencia' => 'por_recepcion', 'activa' => '0'], [$b['tarea']]);
        $this->comprobar('tareas', ['planControl' => '/api/planes-control/'.$b['plan']->getId(), 'puntoControl' => '/api/puntos-control/'.$a['punto']->getId()], []);
        foreach (['planes-control' => 'plan', 'puntos-control' => 'punto'] as $ruta => $key) {
            $this->comprobar($ruta, ['activo' => 'false'], [$b[$key]]);
            $this->comprobar($ruta, ['activo' => 'true', 'order' => ['nombre' => 'DESC'], 'itemsPerPage' => 1], [$a[$key]], $key === 'plan' ? 3 : 2);
            $this->comprobar($ruta, ['order' => ['nombre' => 'asc'], 'itemsPerPage' => 1, 'page' => 2], [$c[$key]], $key === 'plan' ? 4 : 3);
        }
    }

    public function testPaginacionSqlPorDefectoMaximoYDesempate(): void
    {
        $ids = [];
        for ($n = 0; $n < 105; ++$n) {
            $p = (new PuntoControl())->setEstablecimiento($this->local)->setNombre('Repetido')->setTipo(TipoPuntoControl::ZONA)->setActivo(false);
            $this->em->persist($p); $ids[] = $p;
        }
        $this->em->flush();
        $data = $this->coleccion('puntos-control');
        self::assertCount(30, $data['member']); self::assertSame(108, $data['totalItems']);
        $this->comprobar('puntos-control', ['activo' => 'false', 'order' => ['nombre' => 'DESC'], 'itemsPerPage' => 100], array_slice($ids, 0, 100), 106);
        $this->comprobar('puntos-control', ['activo' => 'false', 'order' => ['nombre' => 'DESC'], 'itemsPerPage' => 100, 'page' => 2], [...array_slice($ids, 100), $this->datos[1]['punto']], 106);
        $this->comprobar('puntos-control', ['page' => 100], [], 108);
    }

    #[DataProvider('subrecursos')]
    public function testSubrecursosPaginadosSoloLecturaYPadreValidado(string $padre, string $ruta, string $hijo, string $coleccion): void
    {
        $id = $this->datos[0][$padre]->getId();
        $uri = sprintf($ruta, $id);
        $data = $this->comprobar($uri, ['itemsPerPage' => 1], [$this->datos[0][$hijo]]);
        self::assertSame('/api/'.$coleccion.'/'.$this->datos[0][$hijo]->getId(), $data['member'][0]['@id']);
        $this->comprobar($uri, ['itemsPerPage' => 1, 'page' => 2], [], 1);
        foreach (['POST', 'PATCH', 'DELETE'] as $metodo) { self::assertSame(405, $this->api($metodo, '/api/'.$uri, [])->getStatusCode()); }
        foreach ([$this->ajenos[$padre]->getId(), 2147483647, '999999999999999999999999'] as $ajeno) {
            self::assertSame(404, $this->api('GET', '/api/'.sprintf($ruta, $ajeno))->getStatusCode());
        }
        if ($padre === 'incidencia') {
            $vacia = (new Incidencia())->setEstablecimiento($this->em->find(Establecimiento::class, $this->local->getId()))->setTitulo('Vacía')
                ->setDescripcion('Sin hijos')->setGravedad(GravedadIncidencia::BAJA);
            $this->em->persist($vacia); $this->em->flush();
            $this->comprobar(sprintf($ruta, $vacia->getId()), [], []);
        }
    }

    public static function subrecursos(): array
    {
        return [['incidencia', 'incidencias/%s/acciones', 'accion', 'acciones-correctivas'],
            ['incidencia', 'incidencias/%s/historial', 'historial', 'historiales-incidencia'],
            ['registro', 'registros/%s/evidencias', 'evidencia', 'evidencias']];
    }

    public function testFiltrosDeSubrecursosConservanElPadreYPaginan(): void
    {
        $a = $this->datos[0];
        $otraAccion = (new AccionCorrectiva())->setIncidencia($a['incidencia'])->setUsuario($this->otroUsuario)
            ->setDescripcion('Segunda actuación')->setFechaHora(new \DateTimeImmutable('2026-09-12T09:00:00Z'));
        $otroHistorial = new HistorialIncidencia($a['incidencia'], EstadoIncidencia::ABIERTA, EstadoIncidencia::EN_PROCESO,
            $this->usuario, createdAt: new \DateTimeImmutable('2026-09-12T09:00:00Z'));
        $this->em->persist($otraAccion); $this->em->persist($otroHistorial); $this->em->flush();
        $acciones = 'incidencias/'.$a['incidencia']->getId().'/acciones';
        $historial = 'incidencias/'.$a['incidencia']->getId().'/historial';
        $evidencias = 'registros/'.$a['registro']->getId().'/evidencias';
        $this->comprobar($acciones, ['usuario' => '/api/usuarios/'.$this->otroUsuario->getId(),
            'fechaHora' => ['after' => '2026-09-12T11:00:00+02:00', 'before' => '2026-09-12T09:00:00Z']], [$otraAccion]);
        $this->comprobar($acciones, ['itemsPerPage' => 1, 'page' => 2], [$otraAccion], 2);
        $this->comprobar($historial, ['order' => ['createdAt' => 'desc'], 'itemsPerPage' => 1], [$otroHistorial], 2);
        $this->comprobar($evidencias, ['tipo' => 'foto'], [$a['evidencia']]);
        $this->comprobar($evidencias, ['tipo' => 'documento'], []);
        foreach ([$acciones => 'incidencia=/api/incidencias/', $historial => 'incidencia=/api/incidencias/', $evidencias => 'registro=/api/registros/'] as $ruta => $query) {
            self::assertSame(400, $this->api('GET', '/api/'.$ruta.'?'.$query.'2')->getStatusCode());
        }
    }

    #[DataProvider('relaciones')]
    public function testIriAjenaInexistenteOTipoIncorrectoSiempre404(string $ruta, string $filtro, string $recurso, string $key): void
    {
        // También siendo administrador de ambos establecimientos, el encabezado manda.
        $this->em->persist((new UsuarioEstablecimiento())->setUsuario($this->usuario)->setEstablecimiento($this->otroLocal)->setRol(RolEstablecimiento::ADMIN));
        $this->em->flush();
        foreach (['/api/'.$recurso.'/'.$this->ajenos[$key]->getId(), '/api/'.$recurso.'/2147483647', '/api/establecimientos/'.$this->local->getId(), '/api/'.$recurso.'/999999999999999999999999'] as $iri) {
            $r = $this->api('GET', '/api/'.$ruta.'?'.http_build_query([$filtro => $iri, 'page' => 99]));
            self::assertSame(404, $r->getStatusCode(), $r->getContent());
        }
    }

    public static function relaciones(): array
    {
        return [
            ['registros', 'tareaProgramada', 'tareas-programadas', 'programada'], ['registros', 'tarea', 'tareas', 'tarea'],
            ['incidencias', 'registro', 'registros', 'registro'], ['acciones-correctivas', 'incidencia', 'incidencias', 'incidencia'],
            ['historiales-incidencia', 'incidencia', 'incidencias', 'incidencia'], ['evidencias', 'registro', 'registros', 'registro'],
            ['evidencias', 'incidencia', 'incidencias', 'incidencia'], ['tareas', 'planControl', 'planes-control', 'plan'], ['tareas', 'puntoControl', 'puntos-control', 'punto'],
        ];
    }

    public function testUsuarioConMembresiaAjenaOInactiva404(): void
    {
        $m = $this->em->getRepository(UsuarioEstablecimiento::class)->findOneBy(['usuario' => $this->otroUsuario, 'establecimiento' => $this->local]);
        $m->setActivo(false); $this->em->flush();
        foreach (['registros', 'acciones-correctivas', 'incidencias/'.$this->datos[0]['incidencia']->getId().'/acciones'] as $ruta) {
            self::assertSame(404, $this->api('GET', '/api/'.$ruta.'?'.http_build_query(['usuario' => '/api/usuarios/'.$this->otroUsuario->getId()]))->getStatusCode());
        }
    }

    #[DataProvider('invalidos')]
    public function testParametrosInvalidosDevuelven400(string $ruta, string $query): void
    {
        $r = $this->api('GET', '/api/'.$ruta.'?'.$query);
        self::assertSame(400, $r->getStatusCode(), $r->getContent());
    }

    public static function invalidos(): iterable
    {
        foreach (['registros', 'incidencias', 'acciones-correctivas', 'historiales-incidencia', 'evidencias', 'tareas', 'planes-control', 'puntos-control'] as $ruta) {
            yield [$ruta, 'desconocido=1']; yield [$ruta, 'order[id]=desc'];
        }
        foreach (['page=0', 'page=-1', 'page=1.5', 'page=abc', 'page[]=1', 'page=2147483648', 'itemsPerPage=0', 'itemsPerPage=101', 'itemsPerPage=1.5', 'itemsPerPage[]=1', 'pagination=false', 'conforme=si', 'conforme=', 'conforme[]=true', 'conforme=true&conforme=false', 'usuario[]=1', 'order[fechaHora]=random', 'order[fechaHora][]=asc', 'order=asc', 'fechaHora=2026-09-12', 'fechaHora[strictly_after]=2026-09-12T08:00:00Z', 'fechaHora[after][]=2026-09-12T08:00:00Z'] as $query) { yield ['registros', $query]; }
        foreach (['today', '2026-02-30T08:00:00Z', '2026-09-12', '2026-09-12T08:00:00', '2026-09-12T25:00:00Z', '2026-09-12T08:00:00%2B14:30', '2026-09-12T08:00:00.123Z', '0000-09-12T08:00:00Z'] as $fecha) {
            yield ['registros', 'fechaHora[after]='.$fecha]; yield ['incidencias', 'fechaApertura[before]='.$fecha]; yield ['acciones-correctivas', 'fechaHora[before]='.$fecha];
        }
        yield ['incidencias', 'estado=cerrada']; yield ['incidencias', 'gravedad=urgente']; yield ['incidencias', 'estado[]=abierta'];
        yield ['evidencias', 'tipo=video']; yield ['tareas', 'frecuencia=anual']; yield ['tareas', 'activa=yes'];
        yield ['planes-control', 'activo=TRUE']; yield ['puntos-control', 'order[nombre]=az'];
    }

    #[DataProvider('roles')]
    public function testPermisosJwtTenantYRolesSeConservan(RolEstablecimiento $rol): void
    {
        $m = $this->em->getRepository(UsuarioEstablecimiento::class)->find($this->miembro());
        $m->setRol($rol); $this->em->flush();
        $rutas = ['registros', 'incidencias', 'acciones-correctivas', 'historiales-incidencia', 'evidencias', 'tareas', 'planes-control', 'puntos-control',
            'incidencias/'.$this->datos[0]['incidencia']->getId().'/acciones', 'incidencias/'.$this->datos[0]['incidencia']->getId().'/historial', 'registros/'.$this->datos[0]['registro']->getId().'/evidencias'];
        foreach ($rutas as $ruta) {
            $r = $this->api('GET', '/api/'.$ruta);
            self::assertSame(200, $r->getStatusCode(), $r->getContent());
            self::assertSame(401, $this->request('GET', '/api/'.$ruta, local: $this->local->getId())->getStatusCode());
            self::assertSame(400, $this->request('GET', '/api/'.$ruta, jwt: $this->jwt)->getStatusCode());
            self::assertSame(403, $this->request('GET', '/api/'.$ruta, jwt: $this->jwt, local: $this->otroLocal->getId())->getStatusCode());
        }
        $m = $this->em->getRepository(UsuarioEstablecimiento::class)->find($this->miembro());
        $m->setActivo(false); $this->em->flush();
        foreach ($rutas as $ruta) { self::assertSame(403, $this->api('GET', '/api/'.$ruta)->getStatusCode()); }
    }

    public static function roles(): array { return array_map(static fn ($r) => [$r], RolEstablecimiento::cases()); }
}
