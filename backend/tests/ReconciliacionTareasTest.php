<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\{TareaAPPCC, TareaProgramada, PlanControl};
use App\Enum\{FrecuenciaTarea, EstadoTareaProgramada, TipoPlanControl};
use App\Service\{GeneradorTareasProgramadasService, TareaAPPCCService, TareaProgramadaService};
use App\Tests\Support\PostgresTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReconciliacionTareasTest extends PostgresTestCase
{
    private function generar(): void
    {
        self::getContainer()->get(GeneradorTareasProgramadasService::class)->generar(new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-20'), true);
    }

    public static function cambios(): iterable
    {
        yield 'hora' => ['hora'];
        yield 'frecuencia' => ['frecuencia'];
        yield 'día semanal' => ['semana'];
        yield 'día mensual' => ['mes'];
        yield 'plazo' => ['plazo'];
        yield 'evento' => ['evento'];
    }

    #[DataProvider('cambios')]
    public function testRetiraSoloFuturasYRegeneraCalendarioActual(string $campo): void
    {
        if ($campo === 'semana') { $this->tarea->setFrecuencia(FrecuenciaTarea::SEMANAL)->setDiaSemana(1); }
        if ($campo === 'mes') { $this->tarea->setFrecuencia(FrecuenciaTarea::MENSUAL)->setDiaMes(14); }
        $this->em->flush();
        $this->generar();
        $antiguas = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM tarea_programada');
        self::assertNotEmpty($antiguas);
        match ($campo) {
            'hora' => $this->tarea->setHoraPrevista(new \DateTimeImmutable('10:00:00')),
            'frecuencia' => $this->tarea->setFrecuencia(FrecuenciaTarea::SEMANAL)->setDiaSemana(2),
            'semana' => $this->tarea->setDiaSemana(2),
            'mes' => $this->tarea->setDiaMes(15),
            'plazo' => $this->tarea->setPlazoMinutos(60),
            'evento' => $this->tarea->setFrecuencia(FrecuenciaTarea::BAJO_DEMANDA),
        };
        self::getContainer()->get(TareaAPPCCService::class)->guardarCambios($this->tarea);
        self::assertSame(0, $this->em->getRepository(TareaProgramada::class)->count([]));
        $this->generar();
        // La comparación posterior es ORDER BY id; no depender del plan físico de findAll().
        $nuevas = $this->em->getRepository(TareaProgramada::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(in_array($campo, ['hora', 'plazo'], true) ? 8 : ($campo === 'evento' ? 0 : 1), $nuevas);
        foreach ($nuevas as $p) {
            self::assertNotContains($p->getId(), $antiguas);
            $fecha = $p->getFechaProgramada()->setTimezone(new \DateTimeZone('Europe/Madrid'));
            self::assertSame($campo === 'hora' ? '10:00' : '09:00', $fecha->format('H:i'));
            if ($campo === 'plazo') { self::assertSame(3600, $p->getFechaLimite()->getTimestamp() - $fecha->getTimestamp()); }
            if (in_array($campo, ['semana', 'frecuencia'], true)) { self::assertSame('2', $fecha->format('N')); }
            if ($campo === 'mes') { self::assertSame('15', $fecha->format('d')); }
        }
        $ids = array_map(static fn ($p) => $p->getId(), $nuevas);
        $this->generar();
        self::assertSame($ids, $this->em->getConnection()->fetchFirstColumn('SELECT id FROM tarea_programada ORDER BY id'));
    }

    public function testConservaHistoricosCompletadasRegistrosYOtrasTareas(): void
    {
        $historica = $this->programar('-1 day');
        $ahora = $this->programar('now');
        $registro = $this->registrar();
        $completada = $this->programar('+2 days');
        $completada->completar($this->clock->now());
        $conRegistro = $this->programar('+3 days');
        $this->em->persist($this->registro($conRegistro)->setConforme(true)); // Incluye pendiente con registro heredado.
        $cancelada = $this->programar('+4 days');
        $cancelada->omitir('Cierre del establecimiento.', $this->usuario, $this->clock->now());
        $this->em->flush();
        $plan = (new PlanControl())->setNombre('Otro')->setTipo(TipoPlanControl::TEMPERATURAS)->setEstablecimiento($this->otroLocal);
        $otra = (new TareaAPPCC())->setNombre('Otra empresa')->setEstablecimiento($this->otroLocal)->setPlanControl($plan)->setFrecuencia(FrecuenciaTarea::DIARIA)->setHoraPrevista(new \DateTimeImmutable('09:00:00'));
        $this->em->persist($plan); $this->em->persist($otra); $this->em->flush();
        $ajena = self::getContainer()->get(TareaProgramadaService::class)->programar($otra, $this->otroLocal, $this->clock->now()->modify('+1 day'));
        $vecina = (new TareaAPPCC())->setNombre('Otra tarea del mismo local')->setEstablecimiento($this->local)->setPlanControl($this->tarea->getPlanControl())->setFrecuencia(FrecuenciaTarea::DIARIA)->setHoraPrevista(new \DateTimeImmutable('09:00:00'));
        $this->em->persist($vecina); $this->em->flush();
        self::getContainer()->get(TareaProgramadaService::class)->programar($vecina, $this->local, $this->clock->now()->modify('+1 day'));
        $db = $this->em->getConnection();
        $antes = $db->fetchAllAssociative('SELECT * FROM tarea_programada ORDER BY id');
        $registros = $db->fetchAllAssociative('SELECT * FROM registro_appcc ORDER BY id');
        $eliminable = $this->programar('+5 days');
        $this->tarea->setHoraPrevista(new \DateTimeImmutable('10:00:00'));
        self::getContainer()->get(TareaAPPCCService::class)->guardarCambios($this->tarea);
        self::assertSame($antes, $db->fetchAllAssociative('SELECT * FROM tarea_programada ORDER BY id'));
        self::assertSame($registros, $db->fetchAllAssociative('SELECT * FROM registro_appcc ORDER BY id'));
    }

    public function testCambiosAjenosAlCalendarioNoRetiranEjecuciones(): void
    {
        $this->generar();
        $antes = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM tarea_programada ORDER BY id');
        $this->tarea->setNombre('Nombre nuevo')->setLimiteMaximo('7')->setHoraPrevista(new \DateTimeImmutable('2000-01-01 09:00:00'));
        self::getContainer()->get(TareaAPPCCService::class)->guardarCambios($this->tarea);
        self::assertSame($antes, $this->em->getConnection()->fetchAllAssociative('SELECT * FROM tarea_programada ORDER BY id'));
    }

    public function testRollbackRestauraCalendarioYEjecuciones(): void
    {
        $this->generar();
        $db = $this->em->getConnection();
        $antes = $db->fetchAllAssociative('SELECT * FROM tarea_programada ORDER BY id');
        $db->beginTransaction();
        try {
            $this->tarea->setHoraPrevista(new \DateTimeImmutable('10:00:00'));
            self::getContainer()->get(TareaAPPCCService::class)->guardarCambios($this->tarea);
            $this->em->flush();
            self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM tarea_programada'));
        } finally { $db->rollBack(); $this->em->clear(); }
        self::assertSame($antes, $db->fetchAllAssociative('SELECT * FROM tarea_programada ORDER BY id'));
        self::assertSame('09:00:00', $db->fetchOne('SELECT hora_prevista FROM tarea_appcc WHERE id = ?', [$this->tarea->getId()]));
    }

    public function testProgramarEsIdempotenteEnTransaccionExterior(): void
    {
        $db = $this->em->getConnection(); $db->beginTransaction();
        try {
            $a = $this->programar('+1 day'); $b = $this->programar('+1 day');
            self::assertSame($a, $b);
            $this->em->flush(); $db->commit();
        } finally { if ($db->isTransactionActive()) { $db->rollBack(); } }
        self::assertSame(1, $this->em->getRepository(TareaProgramada::class)->count([]));
    }

    public function testGeneracionAnidadaContabilizaInsercionesPendientes(): void
    {
        $db = $this->em->getConnection(); $db->beginTransaction();
        try {
            $service = self::getContainer()->get(GeneradorTareasProgramadasService::class);
            $desde = new \DateTimeImmutable('2026-09-13'); $hasta = new \DateTimeImmutable('2026-09-15');
            self::assertSame(3, $service->generar($desde, $hasta, true)->creadas);
            $segunda = $service->generar($desde, $hasta, true);
            self::assertSame(0, $segunda->creadas); self::assertSame(3, $segunda->existentes);
            $this->em->flush(); $db->commit();
        } finally { if ($db->isTransactionActive()) { $db->rollBack(); } }
        self::assertSame(3, $this->em->getRepository(TareaProgramada::class)->count([]));
    }

    public function testGeneradorNoDescartaEdicionesSinGuardar(): void
    {
        $this->tarea->setHoraPrevista(new \DateTimeImmutable('10:00:00'));
        try { $this->generar(); self::fail('Debe exigir guardar la definición antes de recargarla.'); }
        catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) { self::assertStringContainsString('Guarda los cambios', $e->getMessage()); }
        self::assertSame('10:00:00', $this->tarea->getHoraPrevista()->format('H:i:s'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tarea_programada'));
    }

    public function testConservaEjecucionQueDejaDeSerFuturaDuranteLaReconciliacion(): void
    {
        $p = $this->programar('+1 second');
        $reloj = new class($this->clock) implements \Symfony\Component\Clock\ClockInterface {
            private int $lecturas = 0;
            public function __construct(private \Symfony\Component\Clock\MockClock $base) {}
            public function now(): \DateTimeImmutable
            {
                if (++$this->lecturas === 2) { $this->base->sleep(2); }
                return $this->base->now();
            }
            public function sleep(float|int $seconds): void { $this->base->sleep($seconds); }
            public function withTimeZone(\DateTimeZone|string $timezone): static { $nuevo = clone $this; $nuevo->base = $this->base->withTimeZone($timezone); return $nuevo; }
        };
        \Symfony\Component\Clock\Clock::set($reloj);
        $this->tarea->setHoraPrevista(new \DateTimeImmutable('10:00:00'));
        self::getContainer()->get(TareaAPPCCService::class)->guardarCambios($this->tarea);
        self::assertSame([$p->getId()], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM tarea_programada'));
    }
}
