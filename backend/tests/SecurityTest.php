<?php
declare(strict_types=1);
namespace App\Tests;

use App\Entity\{AccionCorrectiva, Evidencia, HistorialIncidencia, Incidencia, PlanControl, RegistroAPPCC, Usuario, UsuarioEstablecimiento};
use App\Enum\{RolEstablecimiento, TipoPlanControl};
use App\Service\{IncidenciaService, RegistroAPPCCService};
use App\Tests\Support\PostgresTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SecurityTest extends PostgresTestCase
{
    private string $password;
    private string $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = bin2hex(random_bytes(16));
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->usuario->setPassword($hasher->hashPassword($this->usuario, $this->password));
        $this->otroUsuario->setPassword($hasher->hashPassword($this->otroUsuario, $this->password));
        $this->em->flush();
        $response = $this->request('POST', '/api/login_check', ['email' => $this->usuario->getEmail(), 'password' => $this->password]);
        self::assertSame(200, $response->getStatusCode(), 'Login inicial.');
        $this->jwt = $this->json($response)['token'];
    }

    private function request(string $method, string $uri, ?array $data = null, ?string $token = null, mixed $local = null): Response
    {
        self::getContainer()->get('security.token_storage')->setToken(null);
        $headers = ['CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];
        if ($token !== null) { $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token; }
        if ($local !== null) { $headers['HTTP_X_ESTABLECIMIENTO_ID'] = (string) $local; }
        $request = Request::create($uri, $method, server: $headers, content: $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR));
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        // Emula el fin de la petición: no reutilizar entidades alteradas por un PATCH rechazado.
        $this->em->clear();
        return $response;
    }
    private function json(Response $response): array { return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR); }
    private function api(string $method, string $uri, ?array $data = null): Response { return $this->request($method, $uri, $data, $this->jwt, $this->local->getId()); }
    private function role(RolEstablecimiento $role): void
    {
        $member = $this->em->getRepository(UsuarioEstablecimiento::class)->findOneBy(['usuario' => $this->usuario->getId(), 'establecimiento' => $this->local->getId()]);
        $member->setRol($role); $this->em->flush();
    }
    private function ownProgrammed(): \App\Entity\TareaProgramada
    {
        $this->usuario = $this->em->find(Usuario::class, $this->usuario->getId());
        $this->local = $this->em->find(\App\Entity\Establecimiento::class, $this->local->getId());
        $this->tarea = $this->em->find(\App\Entity\TareaAPPCC::class, $this->tarea->getId());
        return $this->programar();
    }

    public function testLoginNormalizaEmailYNoDevuelveHash(): void
    {
        $r = $this->request('POST', '/api/login_check', ['email' => ' ANA@EXAMPLE.COM ', 'password' => $this->password]);
        self::assertSame(200, $r->getStatusCode());
        self::assertTrue(isset($this->json($r)['token']));
        $r = $this->api('GET', '/api/usuarios/'.$this->usuario->getId());
        self::assertSame(200, $r->getStatusCode());
        self::assertFalse(array_key_exists('password', $this->json($r)), 'Nunca serializar contraseñas.');
    }

    public function testPasswordIncorrecta(): void
    {
        self::assertSame(401, $this->request('POST', '/api/login_check', ['email' => 'ana@example.com', 'password' => bin2hex(random_bytes(8))])->getStatusCode());
    }

    public function testUsuarioInactivoNoIniciaSesionNiUsaJwtPrevio(): void
    {
        $this->em->find(Usuario::class, $this->usuario->getId())->setActivo(false); $this->em->flush();
        self::assertSame(401, $this->request('POST', '/api/login_check', ['email' => 'ana@example.com', 'password' => $this->password])->getStatusCode());
        self::assertSame(401, $this->api('GET', '/api/planes-control')->getStatusCode());
    }

    public function testCuentaSinPasswordNoPuedeAutenticarse(): void
    {
        $this->em->find(Usuario::class, $this->usuario->getId())->setPassword(null); $this->em->flush();
        self::assertSame(401, $this->request('POST', '/api/login_check', ['email' => 'ana@example.com', 'password' => $this->password])->getStatusCode());
    }

    public function testAccesoSinJwt(): void { self::assertSame(401, $this->request('GET', '/api/planes-control', local: $this->local->getId())->getStatusCode()); }
    public function testJwtSinCabecera(): void { self::assertSame(400, $this->request('GET', '/api/planes-control', token: $this->jwt)->getStatusCode()); }

    #[DataProvider('cabecerasInvalidas')]
    public function testFormatoCabecera(mixed $header): void { self::assertSame(400, $this->request('GET', '/api/planes-control', token: $this->jwt, local: $header)->getStatusCode()); }
    public static function cabecerasInvalidas(): array { return [['abc'], ['1.2'], ['-1'], ['0'], ['1e2'], ['999999999999999999999999999999']]; }

    public function testJwtNoConcedeOtroEstablecimiento(): void
    {
        self::assertSame(403, $this->request('GET', '/api/planes-control', token: $this->jwt, local: $this->otroLocal->getId())->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/planes-control', token: $this->jwt, local: 99999999)->getStatusCode());
    }

    #[DataProvider('entidadesInactivas')]
    public function testAmbitoInactivo(string $type): void
    {
        $local = $this->em->find(\App\Entity\Establecimiento::class, $this->local->getId());
        $target = match ($type) {
            'local' => $local,
            'fiscal' => $local->getEntidadFiscal(),
            default => $this->em->getRepository(UsuarioEstablecimiento::class)->findOneBy(['usuario' => $this->usuario->getId(), 'establecimiento' => $local]),
        };
        $target->setActivo(false); $this->em->flush();
        self::assertSame(403, $this->api('GET', '/api/planes-control')->getStatusCode());
    }
    public static function entidadesInactivas(): array { return [['local'], ['fiscal'], ['membresia']]; }

    public function testColeccionesElementosYModificacionesAislados(): void
    {
        $other = (new PlanControl())->setEstablecimiento($this->em->find(\App\Entity\Establecimiento::class, $this->otroLocal->getId()))->setNombre('Plan ajeno')->setTipo(TipoPlanControl::LIMPIEZA);
        $this->em->persist($other); $this->em->flush();
        $r = $this->api('GET', '/api/planes-control');
        self::assertSame(200, $r->getStatusCode());
        $rows = $this->json($r)['member'];
        self::assertCount(1, $rows);
        self::assertSame('Temperaturas', $rows[0]['nombre']);
        self::assertSame(404, $this->api('GET', '/api/planes-control/'.$other->getId())->getStatusCode());
        self::assertSame(404, $this->api('PATCH', '/api/planes-control/'.$other->getId(), ['nombre' => 'Robado'])->getStatusCode());
        self::assertSame('Plan ajeno', $this->em->find(PlanControl::class, $other->getId())->getNombre());
    }

    public function testIriDeOtroTenantRechazada(): void
    {
        $r = $this->api('POST', '/api/planes-control', ['establecimiento' => '/api/establecimientos/'.$this->otroLocal->getId(), 'nombre' => 'Cruce', 'tipo' => 'limpieza']);
        self::assertContains($r->getStatusCode(), [400, 404]);
        self::assertSame(1, $this->em->getRepository(PlanControl::class)->count([]));
    }

    public function testUsuarioDelRegistroProcedeDelJwt(): void
    {
        $p = $this->ownProgrammed();
        $r = $this->api('POST', '/api/registros', ['tareaProgramada' => '/api/tareas-programadas/'.$p->getId(), 'establecimiento' => '/api/establecimientos/'.$this->local->getId(), 'usuario' => '/api/usuarios/'.$this->otroUsuario->getId(), 'valorNumerico' => '9', 'observaciones' => 'Fuera de rango']);
        self::assertSame(400, $r->getStatusCode(), 'Ahora la suplantación se rechaza explícitamente.');
        self::assertSame(0, $this->em->getRepository(RegistroAPPCC::class)->count([]));
        $r = $this->api('POST', '/api/registros', ['tareaProgramada' => '/api/tareas-programadas/'.$p->getId(), 'valorNumerico' => '9', 'observaciones' => 'Fuera de rango']);
        self::assertSame(201, $r->getStatusCode());
        $record = $this->em->getRepository(RegistroAPPCC::class)->findOneBy([]);
        self::assertSame($this->usuario->getId(), $record->getUsuario()->getId());
        $history = $this->em->getRepository(HistorialIncidencia::class)->findOneBy([]);
        self::assertSame($this->usuario->getId(), $history->getCambiadoPor()->getId());
    }

    public function testAuditorSoloLectura(): void
    {
        $p = $this->ownProgrammed(); $this->role(RolEstablecimiento::AUDITOR);
        self::assertSame(200, $this->api('GET', '/api/tareas-programadas')->getStatusCode());
        self::assertSame(403, $this->api('POST', '/api/registros', ['tareaProgramada' => '/api/tareas-programadas/'.$p->getId(), 'valorNumerico' => '3'])->getStatusCode());
    }

    public function testTrabajadorNoModificaConfiguracion(): void
    {
        $configId = $this->local->getConfiguracion()->getId();
        $this->role(RolEstablecimiento::TRABAJADOR);
        self::assertSame(200, $this->api('GET', '/api/configuraciones-establecimiento/'.$configId)->getStatusCode());
        self::assertSame(403, $this->api('PATCH', '/api/configuraciones-establecimiento/'.$configId, ['permiteRegistrosAtrasados' => true])->getStatusCode());
        self::assertSame(403, $this->api('GET', '/api/configuraciones-entidad-fiscal')->getStatusCode());
    }

    public function testResponsableResuelveIncidenciaYAutorDelHistorial(): void
    {
        $p = $this->ownProgrammed();
        $record = self::getContainer()->get(RegistroAPPCCService::class)->registrar($this->registro($p, '9'));
        $i = $record->getIncidencias()->first();
        $this->local->getConfiguracion()->setPermitirCerrarIncidenciaSinAccion(true); $this->em->flush();
        $this->role(RolEstablecimiento::RESPONSABLE);
        self::assertSame(200, $this->api('PATCH', '/api/incidencias/'.$i->getId(), ['estado' => 'resuelta', 'cambiadoPor' => '/api/usuarios/'.$this->otroUsuario->getId()])->getStatusCode());
        $h = $this->em->getRepository(HistorialIncidencia::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame($this->usuario->getId(), $h->getCambiadoPor()->getId());
        self::assertSame('resuelta', $h->getEstadoNuevo()->value);
    }

    public function testAdminGestionaMembresiasYResponsableNo(): void
    {
        // El cambio de rol conserva ahora otro ADMIN activo; mantiene la comprobación original de permisos.
        $segundo = (new UsuarioEstablecimiento())->setUsuario($this->em->find(Usuario::class, $this->otroUsuario->getId()))
            ->setEstablecimiento($this->em->find(\App\Entity\Establecimiento::class, $this->local->getId()))->setRol(RolEstablecimiento::ADMIN);
        $this->em->persist($segundo); $this->em->flush();
        $m = $this->em->getRepository(UsuarioEstablecimiento::class)->findOneBy(['usuario' => $this->usuario->getId(), 'establecimiento' => $this->local->getId()]);
        self::assertSame(200, $this->api('PATCH', '/api/usuarios-establecimientos/'.$m->getId(), ['rol' => 'responsable'])->getStatusCode());
        self::assertSame(403, $this->api('PATCH', '/api/usuarios-establecimientos/'.$m->getId(), ['rol' => 'admin'])->getStatusCode());
    }

    public function testEvidenciaAsociaAutorAutenticado(): void
    {
        $p = $this->ownProgrammed();
        $token = self::getContainer()->get(\App\Service\SubidaEvidenciaService::class)->subir(\App\Tests\Support\EvidenciaFixtures::archivo(), \App\Enum\TipoEvidencia::FOTO, $this->usuario, $this->local)['token'];
        $r = $this->api('POST', '/api/evidencias', ['registro' => '/api/registros/1', 'subidaPor' => '/api/usuarios/'.$this->otroUsuario->getId(), 'tipo' => 'foto', 'storageKey' => 'prueba/foto', 'nombreOriginal' => 'foto.jpg', 'mimeType' => 'image/jpeg', 'tamanoBytes' => 100]);
        self::assertSame(405, $r->getStatusCode(), 'La creación de metadatos desde JSON ya no está expuesta.');
        self::assertSame(0, $this->em->getRepository(Evidencia::class)->count([]));
        $r = $this->api('POST', '/api/registros', ['tareaProgramada' => '/api/tareas-programadas/'.$p->getId(), 'valorNumerico' => '3', 'evidencias' => [['token' => $token, 'tipo' => 'foto']]]);
        self::assertSame(201, $r->getStatusCode());
        self::assertSame($this->usuario->getId(), $this->em->getRepository(Evidencia::class)->findOneBy([])->getSubidaPor()->getId());
    }

    public function testCambioEntreDosMembresiasActivas(): void
    {
        $m = (new UsuarioEstablecimiento())->setUsuario($this->em->find(Usuario::class, $this->usuario->getId()))->setEstablecimiento($this->em->find(\App\Entity\Establecimiento::class, $this->otroLocal->getId()))->setRol(RolEstablecimiento::AUDITOR);
        $this->em->persist($m); $this->em->flush();
        self::assertSame(200, $this->api('GET', '/api/establecimientos/'.$this->local->getId())->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/establecimientos/'.$this->otroLocal->getId(), token: $this->jwt, local: $this->otroLocal->getId())->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/establecimientos/'.$this->otroLocal->getId())->getStatusCode());
    }

    public function testSinRegistroPublicoNiBorradoFiscalNiEscrituraHistorial(): void
    {
        self::assertContains($this->api('POST', '/api/usuarios', [])->getStatusCode(), [404, 405]);
        self::assertContains($this->api('DELETE', '/api/entidades-fiscales/'.$this->local->getEntidadFiscal()->getId())->getStatusCode(), [404, 405]);
        self::assertContains($this->api('POST', '/api/historiales-incidencia', [])->getStatusCode(), [404, 405]);
    }

    public function testTrabajadorRegistraCreaIncidenciaYAccionSinSuplantar(): void
    {
        $p = $this->ownProgrammed();
        $this->role(RolEstablecimiento::TRABAJADOR);
        $r = $this->api('POST', '/api/registros', ['tareaProgramada' => '/api/tareas-programadas/'.$p->getId(), 'valorNumerico' => '3']);
        self::assertSame(201, $r->getStatusCode());
        $r = $this->api('POST', '/api/incidencias', ['establecimiento' => '/api/establecimientos/'.$this->local->getId(), 'titulo' => 'Problema manual', 'descripcion' => 'Detectado en revisión.']);
        self::assertSame(201, $r->getStatusCode());
        $incidentIri = $this->json($r)['@id'];
        $r = $this->api('POST', '/api/acciones-correctivas', ['incidencia' => $incidentIri, 'usuario' => '/api/usuarios/'.$this->otroUsuario->getId(), 'descripcion' => 'Corregir el problema.']);
        self::assertSame(201, $r->getStatusCode());
        self::assertSame($this->usuario->getId(), $this->em->getRepository(AccionCorrectiva::class)->findOneBy([])->getUsuario()->getId());
        self::assertSame(403, $this->api('PATCH', $incidentIri, ['estado' => 'resuelta'])->getStatusCode());
    }

    public function testAislamientoDeTodosLosRecursosDirectosEIndirectos(): void
    {
        // Construir los dos grafos fuera de HTTP: las peticiones posteriores sí usan JWT.
        $this->ownProgrammed();
        $ownLocal = $this->local; $ownUser = $this->usuario; $ownTask = $this->tarea;
        $uris = [];
        foreach ([[$ownLocal, $ownUser, $ownTask], [$this->em->find(\App\Entity\Establecimiento::class, $this->otroLocal->getId()), $this->em->find(Usuario::class, $this->otroUsuario->getId()), null]] as $index => [$local, $user, $task]) {
            if ($task === null) {
                $plan = (new PlanControl())->setEstablecimiento($local)->setTipo(TipoPlanControl::TEMPERATURAS)->setNombre('Otro plan');
                $task = (new \App\Entity\TareaAPPCC())->setEstablecimiento($local)->setPlanControl($plan)->setNombre('Otra tarea')->setFrecuencia(\App\Enum\FrecuenciaTarea::DIARIA)->setHoraPrevista(new \DateTimeImmutable('09:00:00'))->setLimiteMaximo('5');
                $this->em->persist($plan); $this->em->persist($task); $this->em->flush();
            }
            $this->local = $local; $this->usuario = $user; $this->tarea = $task;
            $record = $this->registrar('9');
            $incident = $record->getIncidencias()->first();
            $action = self::getContainer()->get(\App\Service\AccionCorrectivaService::class)->anadir((new AccionCorrectiva())->setIncidencia($incident)->setUsuario($user)->setDescripcion('Acción de prueba'));
            // Fixture de una evidencia histórica de incidencia, con un archivo real privado.
            $storage = self::getContainer()->get(\App\Service\Storage\EvidenciaStorageInterface::class);
            $archivo = $storage->guardarTemporal(\App\Tests\Support\EvidenciaFixtures::archivo('pdf'), \App\Enum\TipoEvidencia::DOCUMENTO);
            $key = $storage->nuevaClaveDefinitiva(); $storage->mover($archivo->storageKey, $key);
            $evidence = (new Evidencia())->setIncidencia($incident)->setSubidaPor($user)->setTipo(\App\Enum\TipoEvidencia::DOCUMENTO)->setStorageKey($key)->setNombreOriginal($archivo->nombreOriginal)->setMimeType($archivo->mimeType)->setTamanoBytes($archivo->tamanoBytes)->setHashSha256($archivo->hashSha256);
            $this->em->persist($evidence);
            $point = (new \App\Entity\PuntoControl())->setEstablecimiento($local)->setNombre('Punto')->setTipo(\App\Enum\TipoPuntoControl::ZONA);
            $this->em->persist($point); $this->em->flush();
            $member = $this->em->getRepository(UsuarioEstablecimiento::class)->findOneBy(['usuario' => $user, 'establecimiento' => $local]);
            $uris[$index] = [
                'establecimientos' => $local->getId(), 'entidades-fiscales' => $local->getEntidadFiscal()->getId(),
                'configuraciones-establecimiento' => $local->getConfiguracion()->getId(), 'configuraciones-entidad-fiscal' => $local->getEntidadFiscal()->getConfiguracion()->getId(),
                'usuarios-establecimientos' => $member->getId(), 'usuarios' => $user->getId(),
                'planes-control' => $task->getPlanControl()->getId(), 'puntos-control' => $point->getId(),
                'tareas' => $task->getId(), 'tareas-programadas' => $record->getTareaProgramada()->getId(),
                'registros' => $record->getId(), 'incidencias' => $incident->getId(),
                'acciones-correctivas' => $action->getId(), 'evidencias' => $evidence->getId(), 'historiales-incidencia' => $incident->getHistorial()->first()->getId(),
            ];
        }
        $this->local = $ownLocal; $this->usuario = $ownUser; $this->tarea = $ownTask;
        foreach ($uris[0] as $path => $ownId) {
            $response = $this->api('GET', '/api/'.$path);
            self::assertSame(200, $response->getStatusCode(), $path);
            $ids = array_column($this->json($response)['member'], 'id');
            self::assertContains($ownId, $ids, $path);
            self::assertNotContains($uris[1][$path], $ids, $path);
            self::assertSame(404, $this->api('GET', '/api/'.$path.'/'.$uris[1][$path])->getStatusCode(), $path);
        }
        self::assertSame(404, $this->api('GET', '/api/evidencias/'.$uris[1]['evidencias'].'/descargar')->getStatusCode());
        $response = $this->api('POST', '/api/evidencias', ['registro' => '/api/registros/'.$uris[1]['registros'], 'tipo' => 'foto', 'storageKey' => 'otro', 'nombreOriginal' => 'otro.jpg', 'mimeType' => 'image/jpeg', 'tamanoBytes' => 10]);
        self::assertSame(405, $response->getStatusCode());
    }

    public function testPatchCalendarioValidaYReconciliaLaAgenda(): void
    {
        $historica = $this->ownProgrammed();
        $futura = $this->programar('+1 day');
        $uri = '/api/tareas/'.$this->tarea->getId();
        self::assertSame(400, $this->api('PATCH', $uri, ['horaPrevista' => '25:00:00'])->getStatusCode());
        self::assertSame(422, $this->api('PATCH', $uri, ['horaPrevista' => null])->getStatusCode());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tarea_programada'));
        $r = $this->api('PATCH', $uri, ['horaPrevista' => '10:00:00']);
        self::assertSame(200, $r->getStatusCode(), $r->getContent());
        self::assertSame('10:00:00', $this->json($r)['horaPrevista']);
        self::assertSame([$historica->getId()], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM tarea_programada'));
    }

    public function testCorsPermiteCabecerasDelFrontend(): void
    {
        $request = Request::create('/api/planes-control', 'OPTIONS', server: ['HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Authorization, Content-Type, X-Establecimiento-Id']);
        $r = self::$kernel->handle($request); self::$kernel->terminate($request, $r);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('x-establecimiento-id', strtolower($r->headers->get('Access-Control-Allow-Headers', '')));
    }
}
