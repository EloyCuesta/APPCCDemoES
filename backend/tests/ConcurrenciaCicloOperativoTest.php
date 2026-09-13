<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\TareaProgramada;
use App\Service\GeneradorTareasProgramadasService;
use App\Tests\Support\PostgresTestCase;
use Symfony\Component\Process\Process;

final class ConcurrenciaCicloOperativoTest extends PostgresTestCase
{
    /** @var list<Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tarea->setCreatedAt(new \DateTimeImmutable('2026-09-01T00:00:00Z'))->setPlazoMinutos(60);
        $this->em->flush();
    }

    private function worker(string $nombre): Process
    {
        $p = new Process([PHP_BINARY, __DIR__.'/Support/operational-worker.php', $nombre], dirname(__DIR__), ['APP_ENV' => 'test']);
        $p->setTimeout(30); $p->start(); $this->workers[] = $p;
        return $p;
    }

    private function esperarBloqueo(string $nombre): void
    {
        $limite = microtime(true) + 15;
        do {
            $db = $this->em->getConnection();
            $db->executeQuery('SELECT pg_stat_clear_snapshot()');
            if ((int) $db->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$nombre]) > 0) { self::assertTrue(true); return; }
            usleep(20000);
        } while (microtime(true) < $limite);
        self::fail('El proceso no llegó al bloqueo: '.implode("\n", array_map(static fn ($p) => $p->getOutput().$p->getErrorOutput(), $this->workers)));
    }

    private function resultado(Process $p): array
    {
        self::assertSame(0, $p->wait(), $p->getOutput().$p->getErrorOutput());
        return json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testDosProcesosDelComandoSonIdempotentesTambienEnVencimientos(): void
    {
        $db = $this->em->getConnection(); $db->beginTransaction();
        $db->fetchOne('SELECT id FROM tarea_appcc WHERE id = ? FOR UPDATE', [$this->tarea->getId()]);
        $a = $this->worker('appcc_ciclo_a'); $b = $this->worker('appcc_ciclo_b');
        $this->esperarBloqueo('appcc_ciclo_a'); $this->esperarBloqueo('appcc_ciclo_b');
        $db->commit();
        $ra = $this->resultado($a); $rb = $this->resultado($b);
        self::assertSame([], $ra['errores']); self::assertSame([], $rb['errores']);
        self::assertSame(3, $ra['creadas'] + $rb['creadas']);
        self::assertSame(3, $ra['existentes'] + $rb['existentes']);
        self::assertSame(2, $ra['vencidas'] + $rb['vencidas']);
        self::assertSame(3, (int) $db->fetchOne('SELECT count(*) FROM tarea_programada'));
        self::assertSame(2, (int) $db->fetchOne("SELECT count(*) FROM tarea_programada WHERE estado = 'vencida'"));
    }

    public function testOmisionConcurrenteMientrasElCicloEsperaNoSeConvierteEnVencida(): void
    {
        self::getContainer()->get(GeneradorTareasProgramadasService::class)->generar(new \DateTimeImmutable('2026-09-11'), new \DateTimeImmutable('2026-09-13'), true);
        $p = $this->em->getRepository(TareaProgramada::class)->findOneBy([], ['fechaProgramada' => 'ASC']);
        $db = $this->em->getConnection(); $db->beginTransaction();
        $db->fetchOne('SELECT id FROM tarea_programada WHERE id = ? FOR UPDATE', [$p->getId()]);
        $a = $this->worker('appcc_ciclo_omision');
        $this->esperarBloqueo('appcc_ciclo_omision');
        $p->omitir('Cierre durante la revisión.', $this->usuario, $this->clock->now());
        $this->em->flush(); $db->commit();
        $r = $this->resultado($a);
        self::assertSame(1, $r['vencidas']);
        $fila = $db->fetchAssociative('SELECT estado, motivo_omision, omitida_por_id FROM tarea_programada WHERE id = ?', [$p->getId()]);
        self::assertSame('omitida', $fila['estado']);
        self::assertSame('Cierre durante la revisión.', $fila['motivo_omision']);
        self::assertSame($this->usuario->getId(), $fila['omitida_por_id']);
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) { $this->em->getConnection()->rollBack(); }
        foreach ($this->workers as $worker) { if ($worker->isRunning()) { $worker->stop(0); } }
        parent::tearDown();
    }
}
