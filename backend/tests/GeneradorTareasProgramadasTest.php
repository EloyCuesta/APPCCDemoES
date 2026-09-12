<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\TareaProgramada;
use App\Enum\FrecuenciaTarea;
use App\Service\GeneradorTareasProgramadasService;
use App\Tests\Support\PostgresTestCase;

final class GeneradorTareasProgramadasTest extends PostgresTestCase
{
    public function testGeneraUnaOcurrenciaDiariaPorDia(): void
    {
        $this->tarea->setCreatedAt(new \DateTimeImmutable('2026-09-12T00:00:00+00:00'));
        $resultado = $this->generar('2026-09-13T00:00:00+00:00', '2026-09-15T23:59:59+00:00');

        self::assertSame(3, $resultado->ocurrenciasCalculadas);
        self::assertSame(3, $resultado->creadas);
        self::assertSame(3, $this->em->getRepository(TareaProgramada::class)->count([]));
    }

    public function testSemanalSoloGeneraEnElDiaConfigurado(): void
    {
        $this->tarea->setFrecuencia(FrecuenciaTarea::SEMANAL)->setDiaSemana(1);
        $this->em->flush();
        $resultado = $this->generar('2026-09-13T00:00:00+00:00', '2026-09-20T23:59:59+00:00');

        self::assertSame(1, $resultado->ocurrenciasCalculadas);
        self::assertSame(1, $this->em->getRepository(TareaProgramada::class)->count([]));
        $programada = $this->em->getRepository(TareaProgramada::class)->findOneBy([]);
        self::assertSame(1, (int) $programada->getFechaProgramada()->setTimezone(new \DateTimeZone('Europe/Madrid'))->format('N'));
    }

    public function testMensualDiaTreintaYUnoUsaUltimoDiaDeFebrero(): void
    {
        $this->tarea->setFrecuencia(FrecuenciaTarea::MENSUAL)->setDiaMes(31)->setCreatedAt(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->em->flush();
        $resultado = $this->generar('2026-02-01T00:00:00+00:00', '2026-02-28T23:59:59+00:00');

        self::assertSame(1, $resultado->ocurrenciasCalculadas);
        $programada = $this->em->getRepository(TareaProgramada::class)->findOneBy([]);
        self::assertSame('2026-02-28', $programada->getFechaProgramada()->setTimezone(new \DateTimeZone('Europe/Madrid'))->format('Y-m-d'));
    }

    public function testSegundaEjecucionEsIdempotente(): void
    {
        $desde = new \DateTimeImmutable('2026-09-13T00:00:00+00:00');
        $hasta = new \DateTimeImmutable('2026-09-15T23:59:59+00:00');
        $service = self::getContainer()->get(GeneradorTareasProgramadasService::class);

        $primera = $service->generar($desde, $hasta);
        $segunda = $service->generar($desde, $hasta);

        self::assertSame(3, $primera->creadas);
        self::assertSame(0, $primera->existentes);
        self::assertSame(0, $segunda->creadas);
        self::assertSame(3, $segunda->existentes);
        self::assertSame(3, $this->em->getRepository(TareaProgramada::class)->count([]));
    }

    public function testRespetaCreatedAtYPlazo(): void
    {
        $this->tarea->setCreatedAt(new \DateTimeImmutable('2026-09-13T10:00:00+00:00'))->setPlazoMinutos(120);
        $this->em->flush();
        $resultado = $this->generar('2026-09-13T00:00:00+00:00', '2026-09-15T23:59:59+00:00');

        self::assertSame(2, $resultado->ocurrenciasCalculadas);
        $programada = $this->em->getRepository(TareaProgramada::class)->findOneBy([], ['fechaProgramada' => 'ASC']);
        self::assertNotNull($programada->getFechaLimite());
        self::assertSame(120 * 60, $programada->getFechaLimite()->getTimestamp() - $programada->getFechaProgramada()->getTimestamp());
    }

    public function testNoGeneraFrecuenciasNoAutomaticasNiTareasInactivas(): void
    {
        $service = self::getContainer()->get(GeneradorTareasProgramadasService::class);
        foreach ([FrecuenciaTarea::POR_TURNO, FrecuenciaTarea::POR_RECEPCION, FrecuenciaTarea::BAJO_DEMANDA] as $frecuencia) {
            $this->tarea->setFrecuencia($frecuencia);
            $this->em->flush();
            self::assertSame(0, $service->generar(new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15T23:59:59+00:00'))->ocurrenciasCalculadas);
        }
        $this->tarea->setFrecuencia(FrecuenciaTarea::DIARIA)->setHoraPrevista(null);
        $this->em->flush();
        $resultado = $service->generar(new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15T23:59:59+00:00'));
        self::assertSame(1, $resultado->ignoradas);
        self::assertStringContainsString('hora prevista', $resultado->ignoradasDetalle[0]);

        $this->tarea->setHoraPrevista(new \DateTimeImmutable('09:00:00'))->setActiva(false);
        $this->em->flush();
        self::assertSame(0, $service->generar(new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15T23:59:59+00:00'))->tareasAnalizadas);

        $this->tarea->setActiva(true)->getPlanControl()->setActivo(false);
        $this->em->flush();
        self::assertSame(0, $service->generar(new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15T23:59:59+00:00'))->tareasAnalizadas);

        $this->tarea->getPlanControl()->setActivo(true);
        $this->local->setActivo(false);
        $this->em->flush();
        self::assertSame(0, $service->generar(new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15T23:59:59+00:00'))->tareasAnalizadas);

        $this->local->setActivo(true);
        $this->local->getEntidadFiscal()->setActivo(false);
        $this->em->flush();
        self::assertSame(0, $service->generar(new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15T23:59:59+00:00'))->tareasAnalizadas);
    }

    public function testRespetaZonaHorariaFiscal(): void
    {
        $this->local->getEntidadFiscal()->getConfiguracion()->setZonaHoraria('America/New_York');
        $this->tarea->setCreatedAt(new \DateTimeImmutable('2026-09-13T00:00:00+00:00'));
        $this->em->flush();
        $this->generar('2026-09-14T00:00:00-04:00', '2026-09-14T23:59:59-04:00');

        $programada = $this->em->getRepository(TareaProgramada::class)->findOneBy([]);
        self::assertSame('09:00', $programada->getFechaProgramada()->setTimezone(new \DateTimeZone('America/New_York'))->format('H:i'));
    }

    private function generar(string $desde, string $hasta): \App\Service\ResultadoGeneracion
    {
        return self::getContainer()->get(GeneradorTareasProgramadasService::class)->generar(
            new \DateTimeImmutable($desde),
            new \DateTimeImmutable($hasta),
        );
    }
}
