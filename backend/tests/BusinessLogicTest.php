<?php

declare(strict_types=1);

use App\Entity\AccionCorrectiva;
use App\Entity\ConfiguracionEntidadFiscal;
use App\Entity\ConfiguracionEstablecimiento;
use App\Entity\EntidadFiscal;
use App\Entity\Establecimiento;
use App\Entity\Incidencia;
use App\Entity\PlanControl;
use App\Entity\PlantillaAPPCC;
use App\Entity\RegistroAPPCC;
use App\Entity\TareaAPPCC;
use App\Entity\Usuario;
use App\Entity\UsuarioEstablecimiento;
use App\Enum\EstadoIncidencia;
use App\Enum\FrecuenciaTarea;
use App\Enum\RolEstablecimiento;
use App\Enum\TipoActividad;
use App\Enum\TipoEntidadFiscal;
use App\Enum\TipoPlanControl;
use App\Exception\BusinessRuleException;
use App\Kernel;
use App\Service\AccionCorrectivaService;
use App\Service\IncidenciaService;
use App\Service\OnboardingService;
use App\Service\PlantillaAPPCCService;
use App\Service\RegistroAPPCCService;
use App\Service\Support\CalendarioAPPCC;
use App\Service\Support\DecimalAPPCC;
use App\Service\TareaAPPCCService;
use App\Service\TareasPendientesService;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = '-1';

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function businessError(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (BusinessRuleException $error) {
        ensure(str_contains($error->getMessage(), $message), 'Error inesperado: '.$error->getMessage());

        return;
    }
    throw new RuntimeException('Se esperaba un error: '.$message);
}

function fiscal(string $nif = 'B12345678'): EntidadFiscal
{
    return (new EntidadFiscal())->setTipo(TipoEntidadFiscal::EMPRESA)->setRazonSocial('Empresa prueba')->setNif($nif)
        ->setDireccion('Mayor 1')->setCodigoPostal('28001')->setLocalidad('Madrid')->setProvincia('Madrid');
}

function local(): Establecimiento
{
    return (new Establecimiento())->setNombre('Obrador')->setTipoActividad(TipoActividad::OBRADOR)
        ->setDireccion('Mayor 1')->setCodigoPostal('28001')->setLocalidad('Madrid')->setProvincia('Madrid');
}

function admin(string $email = 'admin@example.com'): Usuario
{
    return (new Usuario())->setNombre('Ana')->setApellidos('García')->setEmail($email);
}

function plantilla(bool $invalida = false): PlantillaAPPCC
{
    return (new PlantillaAPPCC())->setNombre('Obrador estándar')->setTipoActividad(TipoActividad::OBRADOR)->setConfiguracion([
        'puntosControl' => [['clave' => 'camara', 'nombre' => 'Cámara principal', 'tipo' => 'camara_frigorifica']],
        'planes' => [['nombre' => 'Temperaturas', 'tipo' => 'temperaturas', 'tareas' => [
            ['nombre' => 'Temperatura cámara', 'frecuencia' => 'diaria', 'puntoControl' => 'camara', 'limiteMinimo' => '0', 'limiteMaximo' => '5', 'configuracion' => ['tipoRespuesta' => 'numero']],
            ['nombre' => 'Limpieza cámara', 'frecuencia' => $invalida ? 'inexistente' : 'diaria', 'configuracion' => ['tipoRespuesta' => 'boolean']],
        ]]],
    ]);
}

$scenarios = 0;
function scenario(string $name, callable $test): void
{
    global $scenarios;
    $clock = new MockClock('2026-09-11T12:00:00+00:00');
    Clock::set($clock);
    $kernel = new Kernel('test', true);
    $kernel->boot();
    try {
        $container = $kernel->getContainer()->get('test.service_container');
        $em = $container->get('doctrine')->getManager();
        $connection = $em->getConnection();
        ensure($connection->getDatabasePlatform() instanceof SQLitePlatform && ($connection->getParams()['memory'] ?? false), 'Solo se permiten pruebas en memoria.');
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $test($container, $em, $clock, $kernel);
        ++$scenarios;
        echo 'OK: '.$name."\n";
    } finally {
        $kernel->shutdown();
    }
}

function fixture($container, $em): array
{
    $fiscal = fiscal();
    $local = local();
    $admin = admin();
    $container->get(OnboardingService::class)->crearOnboarding($fiscal, $local, $admin);
    $otraEmpresa = fiscal('B87654321');
    $otroLocal = local()->setEntidadFiscal($otraEmpresa);
    $otroUsuario = admin('otro@example.com');
    $plan = (new PlanControl())->setEstablecimiento($local)->setTipo(TipoPlanControl::TEMPERATURAS)->setNombre('Temperaturas');
    $tarea = (new TareaAPPCC())->setEstablecimiento($local)->setPlanControl($plan)->setNombre('Cámara')->setFrecuencia(FrecuenciaTarea::DIARIA)
        ->setLimiteMinimo('0')->setLimiteMaximo('5')->setConfiguracion(['tipoRespuesta' => 'numero']);
    foreach ([$otraEmpresa, $otroLocal, $otroUsuario, $plan, $tarea] as $entity) {
        $em->persist($entity);
    }
    $em->flush();

    return [$local, $admin, $tarea, $otroLocal, $otroUsuario, $plan];
}

function registro(Establecimiento $local, Usuario $usuario, TareaAPPCC $tarea, MockClock $clock, string $valor = '3'): RegistroAPPCC
{
    return (new RegistroAPPCC())->setEstablecimiento($local)->setUsuario($usuario)->setTarea($tarea)->setValorNumerico($valor)->setFechaHora($clock->now());
}

scenario('onboarding sin plantilla y reutilización de titular/usuario', function ($c, $em): void {
    $fiscal = fiscal();
    $admin = admin();
    $local = $c->get(OnboardingService::class)->crearOnboarding($fiscal, local(), $admin);
    ensure($local->getConfiguracion() instanceof ConfiguracionEstablecimiento, 'Falta configuración local.');
    ensure($fiscal->getConfiguracion() instanceof ConfiguracionEntidadFiscal, 'Falta configuración fiscal.');
    ensure($local->getUsuariosEstablecimiento()->first()->getRol() === RolEstablecimiento::ADMIN, 'Falta el rol ADMIN.');
    $fiscal->getConfiguracion()->setIdioma('en');
    $em->flush();
    $c->get(OnboardingService::class)->crearOnboarding($fiscal, local(), $admin);
    ensure($em->getRepository(Usuario::class)->count([]) === 1, 'Se ha duplicado el usuario.');
    ensure($em->getRepository(ConfiguracionEntidadFiscal::class)->count([]) === 1 && $fiscal->getConfiguracion()->getIdioma() === 'en', 'Se han reemplazado preferencias existentes.');
    ensure($em->getRepository(TareaAPPCC::class)->count([]) === 0, 'No debe aplicarse una plantilla implícita.');
});

scenario('plantilla: pertenencia, límites, tarea numérica y booleana, duplicados', function ($c, $em): void {
    $plantilla = plantilla();
    $em->persist($plantilla);
    $em->flush();
    $local = $c->get(OnboardingService::class)->crearOnboarding(fiscal(), local(), admin(), $plantilla);
    ensure($em->getRepository(TareaAPPCC::class)->count([]) === 2, 'No se han creado las tareas.');
    foreach ($em->getRepository(TareaAPPCC::class)->findAll() as $tarea) {
        ensure($tarea->getEstablecimiento() === $local && $tarea->getPlanControl()->getEstablecimiento() === $local, 'Tarea fuera del local.');
    }
    businessError(fn () => $c->get(PlantillaAPPCCService::class)->aplicar($plantilla, $local), 'Ya existe');
    ensure((int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM tarea_appcc') === 2, 'La segunda aplicación ha duplicado tareas.');
    ensure(!$em->getConnection()->isTransactionActive(), 'Ha quedado una transacción abierta.');
});

scenario('rollback completo de onboarding si la plantilla falla', function ($c, $em): void {
    $plantilla = plantilla(true);
    $em->persist($plantilla);
    $em->flush();
    businessError(fn () => $c->get(OnboardingService::class)->crearOnboarding(fiscal(), local(), admin(), $plantilla), 'Frecuencia');
    foreach (['entidad_fiscal', 'establecimiento', 'usuario', 'usuario_establecimiento', 'configuracion_entidad_fiscal', 'configuracion_establecimiento', 'plan_control', 'punto_control', 'tarea_appcc'] as $table) {
        ensure((int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table) === 0, 'Rollback incompleto: '.$table);
    }
});

scenario('flujo conforme/no conforme, incidencia, acción y resolución', function ($c, $em, $clock): void {
    [$local, $usuario, $tarea] = fixture($c, $em);
    $registros = $c->get(RegistroAPPCCService::class);
    $incidencias = $c->get(IncidenciaService::class);
    $conforme = $registros->registrar(registro($local, $usuario, $tarea, $clock, '3')->setConforme(false));
    ensure($conforme->isConforme() === true, '3 °C debe ser conforme aunque el cliente envíe false.');
    businessError(fn () => $registros->registrar($conforme), 'histórico');
    businessError(fn () => $incidencias->crearDesdeRegistro($conforme), 'registro conforme');
    businessError(fn () => $registros->registrar(registro($local, $usuario, $tarea, $clock, '9')), 'observación');
    $noConforme = $registros->registrar(registro($local, $usuario, $tarea, $clock, '9')->setConforme(true)->setObservaciones('Temperatura elevada.'));
    ensure($noConforme->isConforme() === false, '9 °C debe ser no conforme aunque el cliente envíe true.');
    $incidencia = $em->getRepository(Incidencia::class)->findOneBy(['registro' => $noConforme]);
    ensure($incidencia !== null && $incidencia->getEstado() === EstadoIncidencia::ABIERTA, 'Falta incidencia automática.');
    ensure($incidencias->crearDesdeRegistro($noConforme) === $incidencia, 'Se ha duplicado la incidencia del registro.');
    businessError(fn () => $incidencias->resolver($incidencia->getId()), 'acción correctiva');
    $incidencias->ponerEnProceso($incidencia->getId());
    $accion = $c->get(AccionCorrectivaService::class)->anadir((new AccionCorrectiva())->setIncidencia($incidencia)->setUsuario($usuario)->setDescripcion('Trasladar alimentos a otra cámara.'));
    ensure($incidencia->getEstado() === EstadoIncidencia::EN_PROCESO, 'Añadir una acción no debe cerrar la incidencia.');
    $clock->modify('+1 minute');
    $incidencias->resolver($incidencia->getId());
    ensure($incidencia->getEstado() === EstadoIncidencia::RESUELTA && $incidencia->getFechaCierre()->getTimestamp() === $clock->now()->getTimestamp(), 'Cierre incorrecto.');
    businessError(fn () => $incidencias->ponerEnProceso($incidencia->getId()), 'reabrirse');
    businessError(fn () => $c->get(AccionCorrectivaService::class)->anadir((new AccionCorrectiva())->setIncidencia($incidencia)->setUsuario($usuario)->setDescripcion('Otra acción')), 'resuelta');
    ensure($accion->getId() !== null, 'La acción no está persistida.');
});

scenario('pertenencia entre empresas y estados activos', function ($c, $em, $clock): void {
    [$local, $usuario, $tarea, $otroLocal, $otroUsuario, $plan] = fixture($c, $em);
    $service = $c->get(RegistroAPPCCService::class);
    businessError(fn () => $service->registrar(registro($local, $otroUsuario, $tarea, $clock)), 'no pertenece');
    $otroMiembro = (new UsuarioEstablecimiento())->setUsuario($usuario)->setEstablecimiento($otroLocal)->setRol(RolEstablecimiento::TRABAJADOR);
    $em->persist($otroMiembro);
    $em->flush();
    businessError(fn () => $service->registrar(registro($otroLocal, $usuario, $tarea, $clock)), 'tarea no pertenece');
    $membresia = $em->getRepository(UsuarioEstablecimiento::class)->findOneBy(['usuario' => $usuario, 'establecimiento' => $local]);
    foreach ([[$usuario, 'setActivo'], [$local, 'setActivo'], [$tarea, 'setActiva'], [$membresia, 'setActivo'], [$plan, 'setActivo']] as [$entity, $setter]) {
        $entity->$setter(false);
        $em->flush();
        businessError(fn () => $service->registrar(registro($local, $usuario, $tarea, $clock)), 'inactiv');
        $entity->$setter(true);
        $em->flush();
    }
    $plan->setEstablecimiento($otroLocal);
    $em->flush();
    businessError(fn () => $service->registrar(registro($local, $usuario, $tarea, $clock)), 'plan de control');
    ensure($em->getRepository(RegistroAPPCC::class)->count([]) === 0, 'Se ha persistido un registro no permitido.');
});

scenario('rollback real si falla la persistencia de la incidencia automática', function ($c, $em, $clock): void {
    [$local, $usuario, $tarea] = fixture($c, $em);
    $em->getEventManager()->addEventListener(['postPersist'], new class {
        public function postPersist(PostPersistEventArgs $event): void
        {
            if ($event->getObject() instanceof Incidencia) {
                throw new RuntimeException('Fallo de incidencia simulado después de INSERT.');
            }
        }
    });
    try {
        $c->get(RegistroAPPCCService::class)->registrar(registro($local, $usuario, $tarea, $clock, '9')->setObservaciones('Fuera de rango.'));
        throw new RuntimeException('No se ha provocado el fallo esperado.');
    } catch (RuntimeException $error) {
        ensure(str_contains($error->getMessage(), 'Fallo de incidencia simulado'), $error->getMessage());
    }
    ensure((int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM registro_appcc') === 0, 'El registro ha sobrevivido al rollback.');
    ensure((int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM incidencia') === 0, 'La incidencia ha sobrevivido al rollback.');
    ensure(!$em->getConnection()->isTransactionActive(), 'La transacción no se ha cerrado.');
});

scenario('decimales exactos, booleanos, sin incidencia y resolución sin acción configurable', function ($c, $em, $clock): void {
    [$local, $usuario, $tarea] = fixture($c, $em);
    ensure(DecimalAPPCC::unidades('999999999.999') === 999999999999 && DecimalAPPCC::unidades('-0.001') === -1, 'Se ha perdido precisión decimal.');
    $local->getConfiguracion()->setGeneraIncidenciaAutomatica(false)->setRequiereObservacionNoConforme(false)->setPermitirCerrarIncidenciaSinAccion(true);
    $em->flush();
    foreach (['0', '5', '4.999'] as $valor) {
        ensure($c->get(RegistroAPPCCService::class)->registrar(registro($local, $usuario, $tarea, $clock, $valor))->isConforme(), 'Los límites deben ser inclusivos.');
    }
    ensure(!$c->get(RegistroAPPCCService::class)->registrar(registro($local, $usuario, $tarea, $clock, '5.001'))->isConforme(), 'Comparación decimal incorrecta.');
    ensure($em->getRepository(Incidencia::class)->count([]) === 0, 'Se ha ignorado la configuración de incidencias.');
    $tarea->setLimiteMinimo(null)->setLimiteMaximo(null)->setConfiguracion(['tipoRespuesta' => 'boolean']);
    $em->flush();
    $boolean = registro($local, $usuario, $tarea, $clock)->setValorNumerico(null)->setDatos(['resultado' => false]);
    ensure(!$c->get(RegistroAPPCCService::class)->registrar($boolean)->isConforme(), 'false debe ser no conforme.');
    businessError(fn () => $c->get(RegistroAPPCCService::class)->registrar(registro($local, $usuario, $tarea, $clock)->setDatos(['resultado' => 'false'])), 'booleano');
    $manual = $c->get(IncidenciaService::class)->crearManual((new Incidencia())->setEstablecimiento($local)->setTitulo('Avería')->setDescripcion('Puerta averiada'));
    $c->get(IncidenciaService::class)->resolver($manual->getId());
    ensure($manual->getEstado() === EstadoIncidencia::RESUELTA, 'La configuración debe permitir cerrar sin acción.');
});

scenario('pendientes por períodos, zona horaria, atrasos y conservación del histórico', function ($c, $em, $clock): void {
    [$local, $usuario, $tarea, , , $plan] = fixture($c, $em);
    $pendientes = $c->get(TareasPendientesService::class);
    ensure(in_array($tarea, $pendientes->obtenerPendientes($local), true), 'Una diaria sin registro debe estar pendiente.');
    $registro = $c->get(RegistroAPPCCService::class)->registrar(registro($local, $usuario, $tarea, $clock));
    ensure(!in_array($tarea, $pendientes->obtenerPendientes($local), true), 'Una diaria completada no debe estar pendiente.');
    foreach ([FrecuenciaTarea::SEMANAL, FrecuenciaTarea::MENSUAL, FrecuenciaTarea::POR_TURNO, FrecuenciaTarea::POR_RECEPCION, FrecuenciaTarea::BAJO_DEMANDA] as $frecuencia) {
        $otra = (new TareaAPPCC())->setEstablecimiento($local)->setPlanControl($plan)->setNombre($frecuencia->value)->setFrecuencia($frecuencia)->setConfiguracion(['tipoRespuesta' => 'boolean']);
        $em->persist($otra);
    }
    $em->flush();
    $frecuencias = array_map(static fn (TareaAPPCC $t) => $t->getFrecuencia(), $pendientes->obtenerPendientes($local));
    ensure($frecuencias === [FrecuenciaTarea::SEMANAL, FrecuenciaTarea::MENSUAL], 'Se han inventado pendientes para frecuencias de evento o turno.');
    $calendario = $c->get(CalendarioAPPCC::class);
    [$inicio, $fin] = $calendario->periodo($tarea, $local, new DateTimeImmutable('2026-03-29T12:00:00+00:00'));
    ensure($fin->getTimestamp() - $inicio->getTimestamp() === 23 * 3600, 'El período diario debe respetar el cambio de hora.');
    $clock->modify('2026-09-12T00:30:00+02:00');
    ensure(in_array($tarea, $pendientes->obtenerPendientes($local), true), 'Debe empezar un nuevo período local.');
    $atrasado = registro($local, $usuario, $tarea, $clock)->setFechaHora($clock->now()->modify('-1 hour'));
    businessError(fn () => $c->get(RegistroAPPCCService::class)->registrar($atrasado), 'períodos anteriores');
    $local->getConfiguracion()->setPermiteRegistrosAtrasados(true)->setMaximoMinutosRegistroAtrasado(30);
    $em->flush();
    businessError(fn () => $c->get(RegistroAPPCCService::class)->registrar($atrasado), 'máximo');
    $local->getConfiguracion()->setMaximoMinutosRegistroAtrasado(60);
    $em->flush();
    $c->get(RegistroAPPCCService::class)->registrar($atrasado);
    $tarea->setLimiteMaximo('2');
    $c->get(TareaAPPCCService::class)->guardarCambios($tarea);
    $c->get(TareaAPPCCService::class)->desactivar($tarea->getId());
    $em->clear();
    $historico = $em->find(RegistroAPPCC::class, $registro->getId());
    ensure($historico->isConforme() && $historico->getValorNumerico() === '3', 'Se ha recalculado o perdido el histórico.');
});

scenario('processors HTTP: calcular conformidad y proteger transiciones', function ($c, $em, $clock, $kernel): void {
    [$local, $usuario, $tarea, , $otroUsuario] = fixture($c, $em);
    $call = static function (string $method, string $uri, array $payload, int $status) use ($kernel, $em): array {
        $request = Request::create($uri, $method, server: ['CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: json_encode($payload, JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        ensure($response->getStatusCode() === $status, $method.' '.$uri.': '.$response->getStatusCode().' '.($data['detail'] ?? $data['description'] ?? ''));
        $em->clear();

        return $data;
    };
    $payload = ['tarea' => '/api/tareas/'.$tarea->getId(), 'establecimiento' => '/api/establecimientos/'.$local->getId(), 'usuario' => '/api/usuarios/'.$usuario->getId(), 'valorNumerico' => '9', 'conforme' => true, 'observaciones' => 'Temperatura elevada.'];
    $call('POST', '/api/registros', array_replace($payload, ['usuario' => '/api/usuarios/'.$otroUsuario->getId()]), 422);
    $registro = $call('POST', '/api/registros', $payload, 201);
    ensure($registro['conforme'] === false, 'El processor ha confiado en el conforme del cliente.');
    $incidencia = $em->getRepository(Incidencia::class)->findOneBy(['registro' => $registro['id']]);
    $uri = '/api/incidencias/'.$incidencia->getId();
    $call('PATCH', $uri, ['estado' => 'resuelta'], 422);
    $call('POST', '/api/acciones-correctivas', ['incidencia' => $uri, 'usuario' => '/api/usuarios/'.$usuario->getId(), 'descripcion' => 'Trasladar alimentos.'], 201);
    $resolved = $call('PATCH', $uri, ['estado' => 'resuelta', 'fechaCierre' => '2000-01-01T00:00:00+00:00'], 200);
    ensure(!str_starts_with($resolved['fechaCierre'], '2000-'), 'Se ha aceptado la fecha de cierre enviada por el cliente.');
    $call('PATCH', $uri, ['estado' => 'abierta'], 422);
});

echo "OK: $scenarios escenarios de negocio, sin modificar PostgreSQL.\n";
