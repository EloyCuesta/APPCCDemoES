<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\TareaProgramada;
use App\Service\{GeneradorTareasProgramadasService, TareaAPPCCService, RegistroAPPCCService};
use App\Tests\Support\PostgresTestCase;
use Symfony\Component\Process\Process;

final class ConcurrenciaCalendarioTest extends PostgresTestCase
{
    /** @var list<Process> */
    private array $workers = [];

    private function worker(string $accion, string $nombre): Process
    {
        $process = new Process([PHP_BINARY, __DIR__.'/Support/recurrence-worker.php', $accion, (string) $this->tarea->getId(), $nombre], dirname(__DIR__), ['APP_ENV' => 'test']);
        $process->setTimeout(30);
        $process->start();
        $this->workers[] = $process;
        return $process;
    }

    private function esperarBloqueo(string $nombre): void
    {
        $limite = microtime(true) + 15;
        do {
            $this->em->getConnection()->executeQuery('SELECT pg_stat_clear_snapshot()');
            if ((int) $this->em->getConnection()->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$nombre]) > 0) {
                self::assertTrue(true); return;
            }
            usleep(20000);
        } while (microtime(true) < $limite);
        self::fail('El segundo proceso no llegó al bloqueo PostgreSQL: '.implode("\n", array_map(static fn ($p) => $p->getOutput().$p->getErrorOutput(), $this->workers)));
    }

    private function bloquearTarea(): void
    {
        $db = $this->em->getConnection(); $db->beginTransaction();
        $db->fetchOne('SELECT id FROM tarea_appcc WHERE id = ? FOR UPDATE', [$this->tarea->getId()]);
    }

    private function terminar(Process $worker): string
    {
        self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
        return $worker->getOutput();
    }

    public function testDosGeneradoresSolapadosNoDuplicanYContabilizanCorrectamente(): void
    {
        $this->bloquearTarea();
        $a = $this->worker('generar', 'appcc_generador_a');
        $b = $this->worker('generar', 'appcc_generador_b');
        $this->esperarBloqueo('appcc_generador_a'); $this->esperarBloqueo('appcc_generador_b');
        $this->em->getConnection()->commit();
        $resultados = [$this->terminar($a), $this->terminar($b)];
        self::assertSame(3, $this->em->getRepository(TareaProgramada::class)->count([]));
        self::assertStringContainsString('"creadas":3,"existentes":0', implode('', $resultados));
        self::assertStringContainsString('"creadas":0,"existentes":3', implode('', $resultados));
    }

    public function testGeneradorQueEsperabaRecargaNuevaHora(): void
    {
        $this->bloquearTarea();
        $worker = $this->worker('generar', 'appcc_generador_cambio');
        $this->esperarBloqueo('appcc_generador_cambio');
        $this->tarea->setHoraPrevista(new \DateTimeImmutable('10:00:00'));
        self::getContainer()->get(TareaAPPCCService::class)->guardarCambios($this->tarea);
        $this->em->flush(); $this->em->getConnection()->commit();
        $this->terminar($worker);
        $horas = $this->em->getConnection()->fetchFirstColumn('SELECT fecha_programada FROM tarea_programada');
        self::assertCount(3, $horas);
        foreach ($horas as $hora) { self::assertStringEndsWith('08:00:00', $hora); }
    }

    public function testEdicionRetiraOcurrenciasGeneradasMientrasEsperaba(): void
    {
        $this->bloquearTarea();
        $worker = $this->worker('editar', 'appcc_editor');
        $this->esperarBloqueo('appcc_editor');
        self::getContainer()->get(GeneradorTareasProgramadasService::class)->generar(new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15'), true);
        $this->em->flush(); $this->em->getConnection()->commit();
        $this->terminar($worker);
        self::assertSame(0, $this->em->getRepository(TareaProgramada::class)->count([]));
        self::assertSame('10:00:00', $this->em->getConnection()->fetchOne('SELECT hora_prevista FROM tarea_appcc WHERE id = ?', [$this->tarea->getId()]));
    }

    public function testGeneradorRevalidaDesactivacionConcurrente(): void
    {
        $this->bloquearTarea();
        $worker = $this->worker('generar', 'appcc_inactivo');
        $this->esperarBloqueo('appcc_inactivo');
        $this->tarea->getPlanControl()->setActivo(false); $this->em->flush(); $this->em->getConnection()->commit();
        $this->terminar($worker);
        self::assertSame(0, $this->em->getRepository(TareaProgramada::class)->count([]));
    }

    public function testEdicionObsoletaFallaSinBorrarAgenda(): void
    {
        $this->bloquearTarea();
        $worker = $this->worker('editar', 'appcc_editor_obsoleto');
        $this->esperarBloqueo('appcc_editor_obsoleto');
        $this->tarea->setHoraPrevista(new \DateTimeImmutable('11:00:00'));
        self::getContainer()->get(TareaAPPCCService::class)->guardarCambios($this->tarea);
        $this->em->flush(); $this->em->getConnection()->commit();
        self::assertNotSame(0, $worker->wait());
        self::assertStringContainsString('La tarea ha cambiado', $worker->getErrorOutput());
        self::assertSame('11:00:00', $this->em->getConnection()->fetchOne('SELECT hora_prevista FROM tarea_appcc WHERE id = ?', [$this->tarea->getId()]));
    }

    public function testRegistroConcurrenteSeConservaTrasEsperarSuBloqueo(): void
    {
        $p = $this->programar('+1 day');
        $db = $this->em->getConnection(); $db->beginTransaction();
        $db->fetchOne('SELECT id FROM tarea_programada WHERE id = ? FOR UPDATE', [$p->getId()]);
        $worker = $this->worker('editar', 'appcc_editor_registro');
        $this->esperarBloqueo('appcc_editor_registro');
        $this->clock->sleep(86401);
        self::getContainer()->get(RegistroAPPCCService::class)->registrar($this->registro($p));
        $this->em->flush(); $db->commit();
        $this->terminar($worker);
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM registro_appcc'));
        self::assertSame('completada', $db->fetchOne('SELECT estado FROM tarea_programada WHERE id = ?', [$p->getId()]));
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) { $this->em->getConnection()->rollBack(); }
        foreach ($this->workers as $worker) { if ($worker->isRunning()) { $worker->stop(0); } }
        parent::tearDown();
    }
}
