<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\{AccionCorrectiva, Evidencia, HistorialIncidencia, Incidencia, RegistroAPPCC, TareaProgramada, UsuarioEstablecimiento};
use App\Enum\{EstadoIncidencia, EstadoTareaProgramada, TipoEvidencia, FrecuenciaTarea};
use App\Exception\BusinessRuleException;
use App\Service\{RegistroAPPCCService, IncidenciaService, TareaProgramadaService, TareasPendientesService, TareaAPPCCService, AccionCorrectivaService};
use App\Tests\Support\PostgresTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Event\PostPersistEventArgs;
use PHPUnit\Framework\Attributes\DataProvider;

final class BusinessLogicTest extends PostgresTestCase
{
    public function testProgramacionIdempotente(): void
    {
        $p = $this->programar();
        self::assertSame($p, $this->programar());
        self::assertNotNull($p->getId());
        self::assertSame(EstadoTareaProgramada::PENDIENTE, $p->getEstado());
        self::assertSame(1, $this->em->getRepository(TareaProgramada::class)->count([]));
    }

    public function testRechazaOtroEstablecimiento(): void
    {
        $this->expectException(BusinessRuleException::class);
        self::getContainer()->get(TareaProgramadaService::class)->programar($this->tarea, $this->otroLocal, $this->clock->now());
    }

    #[DataProvider('membresiasInvalidas')]
    public function testAsignacionExigeMembresiaActiva(bool $inactiva): void
    {
        $usuario = $this->otroUsuario;
        if ($inactiva) {
            $usuario = $this->usuario;
            $this->em->getRepository(UsuarioEstablecimiento::class)->findOneBy(['usuario' => $usuario, 'establecimiento' => $this->local])->setActivo(false);
            $this->em->flush();
        }
        $this->expectException(BusinessRuleException::class);
        self::getContainer()->get(TareaProgramadaService::class)->programar($this->tarea, $this->local, $this->clock->now(), $usuario);
    }
    public static function membresiasInvalidas(): array { return [[false], [true]]; }

    public function testFechaLimiteNoPrecedeProgramacion(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->programar(limite: $this->clock->now()->modify('-2 minutes'));
    }

    public function testRegistroCompletaEjecucionYCalculaResultado(): void
    {
        $p = $this->programar();
        $r = self::getContainer()->get(RegistroAPPCCService::class)->registrar($this->registro($p)->setConforme(false));
        $this->em->clear();
        $p = $this->em->find(TareaProgramada::class, $p->getId());
        self::assertSame(EstadoTareaProgramada::COMPLETADA, $p->getEstado());
        self::assertSame($this->clock->now()->getTimestamp(), $p->getCompletadaAt()->getTimestamp());
        self::assertTrue($p->getRegistro()->isConforme());
        self::assertSame($r->getId(), $p->getRegistro()->getId());
    }

    public function testNoPermiteSegundoRegistro(): void
    {
        $r = $this->registrar();
        $this->expectException(BusinessRuleException::class);
        self::getContainer()->get(RegistroAPPCCService::class)->registrar($this->registro($r->getTareaProgramada()));
    }

    public function testCompletadaNoVuelveAPendiente(): void
    {
        $r = $this->registrar();
        $this->expectException(BusinessRuleException::class);
        self::getContainer()->get(TareaProgramadaService::class)->cambiarEstado($r->getTareaProgramada(), EstadoTareaProgramada::PENDIENTE);
    }

    public function testOrigenRegistradoNoSePuedeCambiar(): void
    {
        $r = $this->registrar();
        $this->expectException(BusinessRuleException::class);
        $r->getTareaProgramada()->setEstablecimiento($this->otroLocal);
    }

    public function testRollbackConjuntoDeRegistroEstadoIncidenciaEHistorial(): void
    {
        $p = $this->programar();
        $listener = new class {
            public function postPersist(PostPersistEventArgs $args): void
            {
                if ($args->getObject() instanceof HistorialIncidencia) { throw new \RuntimeException('Fallo de persistencia intencional.'); }
            }
        };
        $this->em->getEventManager()->addEventListener(['postPersist'], $listener);
        try {
            self::getContainer()->get(RegistroAPPCCService::class)->registrar($this->registro($p, '9'));
            self::fail('Se esperaba rollback.');
        } catch (\RuntimeException $e) {
            self::assertSame('Fallo de persistencia intencional.', $e->getMessage());
        }
        $db = $this->em->getConnection();
        foreach (['registro_appcc', 'incidencia', 'historial_incidencia'] as $table) { self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM '.$table)); }
        self::assertSame('pendiente', $db->fetchOne('SELECT estado FROM tarea_programada WHERE id = ?', [$p->getId()]));
        self::assertNull($db->fetchOne('SELECT completada_at FROM tarea_programada WHERE id = ?', [$p->getId()]));
    }

    public function testVencidasYPendientesSonEjecucionesExplicitas(): void
    {
        $service = self::getContainer()->get(TareaProgramadaService::class);
        $agenda = self::getContainer()->get(TareasPendientesService::class);
        self::assertSame([], $agenda->obtenerPendientes($this->local));
        $p = $this->programar('-2 hours', $this->clock->now()->modify('-1 hour'));
        self::assertSame(1, $service->detectarVencidas($this->local));
        self::assertSame(EstadoTareaProgramada::VENCIDA, $p->getEstado());
        self::assertSame(0, $service->detectarVencidas($this->local));
        self::assertSame([$p], $agenda->obtenerPendientes($this->local));
        self::assertSame([], $agenda->obtenerPendientes($this->otroLocal));
    }

    public function testRegistroVencidoRespetaConfiguracion(): void
    {
        $p = $this->programar('-2 hours', $this->clock->now()->modify('-1 hour'));
        $this->local->getConfiguracion()->setPermiteRegistrosAtrasados(true)->setMaximoMinutosRegistroAtrasado(60);
        $this->em->flush();
        self::getContainer()->get(TareaProgramadaService::class)->detectarVencidas($this->local);
        self::getContainer()->get(RegistroAPPCCService::class)->registrar($this->registro($p));
        self::assertSame(EstadoTareaProgramada::COMPLETADA, $p->getEstado());
    }

    public function testRegistroVencidoSinPermisoSeRechaza(): void
    {
        $p = $this->programar('-2 hours', $this->clock->now()->modify('-1 hour'));
        $this->expectException(BusinessRuleException::class);
        self::getContainer()->get(RegistroAPPCCService::class)->registrar($this->registro($p));
    }

    public function testFrecuenciasDeEventoNoInventanOcurrencias(): void
    {
        foreach ([FrecuenciaTarea::POR_TURNO, FrecuenciaTarea::POR_RECEPCION, FrecuenciaTarea::BAJO_DEMANDA] as $frecuencia) {
            $this->tarea->setFrecuencia($frecuencia);
            $this->em->flush();
            self::assertSame([], self::getContainer()->get(TareasPendientesService::class)->obtenerPendientes($this->local));
        }
        self::assertNotNull($this->programar()->getId());
    }

    #[DataProvider('padresEvidencia')]
    public function testEvidenciaXor(bool $registro, bool $incidencia, bool $valida): void
    {
        $r = $this->registrar('9');
        $i = $r->getIncidencias()->first();
        $e = (new Evidencia())->setSubidaPor($this->usuario)->setTipo(TipoEvidencia::FOTO)->setStorageKey('evidencias/prueba')
            ->setNombreOriginal('foto.jpg')->setMimeType('image/jpeg')->setTamanoBytes(100)->setHashSha256(str_repeat('a', 64));
        if ($registro) { $e->setRegistro($r); }
        if ($incidencia) { $e->setIncidencia($i); }
        self::assertSame($valida, count(self::getContainer()->get('validator')->validate($e)) === 0);
        if (!$valida) { $this->expectException(\Doctrine\DBAL\Exception\DriverException::class); }
        $this->em->persist($e);
        $this->em->flush();
        self::assertNotNull($e->getId());
    }
    public static function padresEvidencia(): array { return [[true, false, true], [false, true, true], [false, false, false], [true, true, false]]; }

    public function testTamanoYHashSeValidan(): void
    {
        $e = (new Evidencia())->setTamanoBytes(-1)->setHashSha256(str_repeat('z', 64));
        $paths = [];
        foreach (self::getContainer()->get('validator')->validate($e) as $v) { $paths[] = $v->getPropertyPath(); }
        self::assertContains('tamanoBytes', $paths);
        self::assertContains('hashSha256', $paths);
    }

    public function testIncidenciaUnicaPorRegistroEnPostgres(): void
    {
        $r = $this->registrar('9');
        self::assertSame($r->getIncidencias()->first(), self::getContainer()->get(IncidenciaService::class)->crearDesdeRegistro($r));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement('INSERT INTO incidencia (establecimiento_id, registro_id, titulo, descripcion, gravedad, estado, fecha_apertura, created_at) SELECT establecimiento_id, registro_id, titulo, descripcion, gravedad, estado, fecha_apertura, created_at FROM incidencia');
    }

    public function testConflictoDeIncidenciaSeTraduceA409(): void
    {
        $r = $this->registrar('9');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        self::getContainer()->get(IncidenciaService::class)->crearManual((new Incidencia())->setEstablecimiento($this->local)->setRegistro($r)->setTitulo('Duplicada')->setDescripcion('Otro intento'), $this->usuario);
    }

    public function testHistorialManualTransicionesYConservacion(): void
    {
        $service = self::getContainer()->get(IncidenciaService::class);
        $i = $service->crearManual((new Incidencia())->setEstablecimiento($this->local)->setTitulo('Manual')->setDescripcion('Incidencia manual'), $this->usuario);
        $service->crearManual((new Incidencia())->setEstablecimiento($this->local)->setTitulo('Otra')->setDescripcion('Otra incidencia'), $this->usuario);
        self::assertCount(1, $i->getHistorial());
        self::assertNull($i->getHistorial()->first()->getEstadoAnterior());
        self::assertSame($this->usuario, $i->getHistorial()->first()->getCambiadoPor());
        $service->ponerEnProceso($i->getId(), $this->usuario);
        $service->ponerEnProceso($i->getId(), $this->usuario);
        self::assertCount(2, $i->getHistorial());
        self::getContainer()->get(AccionCorrectivaService::class)->anadir((new AccionCorrectiva())->setIncidencia($i)->setUsuario($this->usuario)->setDescripcion('Corregir problema'));
        $service->resolver($i->getId(), $this->usuario);
        self::assertCount(3, $i->getHistorial());
        self::assertSame(EstadoIncidencia::RESUELTA, $i->getEstado());
        self::getContainer()->get(TareaAPPCCService::class)->desactivar($this->tarea->getId());
        self::assertSame(4, $this->em->getRepository(HistorialIncidencia::class)->count([]));
        self::assertSame(2, $this->em->getRepository(Incidencia::class)->count(['registro' => null]));
    }

    public function testHistorialAppendOnlyEnPostgres(): void
    {
        $this->registrar('9');
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->em->getConnection()->executeStatement("UPDATE historial_incidencia SET comentario = 'alterado'");
    }

    public function testCalendarioRespetaCambioDeHora(): void
    {
        [$inicio, $fin] = self::getContainer()->get(\App\Service\Support\CalendarioAPPCC::class)->periodo($this->tarea, $this->local, new \DateTimeImmutable('2026-03-29T12:00:00+00:00'));
        self::assertSame(23 * 3600, $fin->getTimestamp() - $inicio->getTimestamp());
    }
}
