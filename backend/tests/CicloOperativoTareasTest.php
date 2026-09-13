<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\{Establecimiento, PlanControl, TareaAPPCC, TareaProgramada};
use App\Enum\{EstadoTareaProgramada, FrecuenciaTarea, TipoPlanControl};
use App\Service\{CicloOperativoTareasService, TareaProgramadaService};
use App\Tests\Support\PostgresTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CicloOperativoTareasTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->tarea->setCreatedAt(new \DateTimeImmutable('2026-09-01T00:00:00Z'))->setPlazoMinutos(60);
        $this->em->flush();
    }

    private function comando(array $opciones = [], int $codigo = 0): array
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:tareas:procesar'));
        self::assertSame($codigo, $tester->execute($opciones + ['--json' => true]), $tester->getDisplay());
        return json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testComandoRepetidoNoDuplicaYDetectaVencidas(): void
    {
        $a = $this->comando();
        self::assertSame(1, $a['tareasAnalizadas']);
        self::assertSame(9, $a['creadas']);
        self::assertSame(2, $a['vencidas']);
        self::assertSame([], $a['errores']);
        $filas = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM tarea_programada ORDER BY id');
        $b = $this->comando();
        self::assertSame(0, $b['creadas']);
        self::assertSame(9, $b['existentes']);
        self::assertSame(0, $b['vencidas']);
        self::assertSame($filas, $this->em->getConnection()->fetchAllAssociative('SELECT * FROM tarea_programada ORDER BY id'));
    }

    public function testRecuperaAyerYHoyTrasUnaVentanaNoProcesada(): void
    {
        $this->comando(['--desde' => '2026-09-10', '--hasta' => '2026-09-10']);
        $r = $this->comando(['--horizonte-dias' => '1']);
        self::assertSame(3, $r['creadas']);
        self::assertSame(2, $r['vencidas']);
        $fechas = $this->em->getConnection()->fetchFirstColumn('SELECT fecha_programada FROM tarea_programada ORDER BY fecha_programada');
        self::assertSame(['2026-09-10 07:00:00', '2026-09-11 07:00:00', '2026-09-12 07:00:00', '2026-09-13 07:00:00'], $fechas);
    }

    private function tareaEn(Establecimiento $local, string $nombre): TareaAPPCC
    {
        $plan = (new PlanControl())->setNombre($nombre)->setEstablecimiento($local)->setTipo(TipoPlanControl::TEMPERATURAS);
        $tarea = (new TareaAPPCC())->setNombre($nombre)->setEstablecimiento($local)->setPlanControl($plan)
            ->setFrecuencia(FrecuenciaTarea::DIARIA)->setHoraPrevista(new \DateTimeImmutable('09:00:00'))->setPlazoMinutos(60)->setCreatedAt(new \DateTimeImmutable('2026-09-01T00:00:00Z'));
        $this->em->persist($plan); $this->em->persist($tarea); $this->em->flush();
        return $tarea;
    }

    public function testZonasFiscalesYDiasLocalesEnLosDosExtremosDelMundo(): void
    {
        $this->local->getEntidadFiscal()->getConfiguracion()->setZonaHoraria('Pacific/Kiritimati');
        $this->otroLocal->getEntidadFiscal()->getConfiguracion()->setZonaHoraria('Pacific/Pago_Pago');
        $otra = $this->tareaEn($this->otroLocal, 'Otra zona');
        $id = $otra->getId();
        $r = $this->comando(['--horizonte-dias' => '1']);
        self::assertSame(6, $r['creadas']);
        self::assertSame(2, $r['tareasAnalizadas']);
        $db = $this->em->getConnection();
        self::assertSame(['2026-09-11 19:00:00', '2026-09-12 19:00:00', '2026-09-13 19:00:00'], $db->fetchFirstColumn('SELECT fecha_programada FROM tarea_programada WHERE tarea_id = ? ORDER BY fecha_programada', [$this->tarea->getId()]));
        self::assertSame(['2026-09-10 20:00:00', '2026-09-11 20:00:00', '2026-09-12 20:00:00'], $db->fetchFirstColumn('SELECT fecha_programada FROM tarea_programada WHERE tarea_id = ? ORDER BY fecha_programada', [$id]));
    }

    public function testVentanasExplicitasSeInterpretanEnCadaZonaFiscal(): void
    {
        $this->otroLocal->getEntidadFiscal()->getConfiguracion()->setZonaHoraria('America/New_York');
        $otra = $this->tareaEn($this->otroLocal, 'Nueva York'); $id = $otra->getId();
        $r = $this->comando(['--desde' => '2026-09-14', '--hasta' => '2026-09-14']);
        self::assertSame(2, $r['creadas']);
        self::assertSame('2026-09-14 13:00:00', $this->em->getConnection()->fetchOne('SELECT fecha_programada FROM tarea_programada WHERE tarea_id = ?', [$id]));
        self::assertSame('2026-09-14 07:00:00', $this->em->getConnection()->fetchOne('SELECT fecha_programada FROM tarea_programada WHERE tarea_id = ?', [$this->tarea->getId()]));
    }

    #[DataProvider('ambitos')]
    public function testSoloProcesaAmbitosActivos(string $ambito): void
    {
        $p = $this->programar('-2 hours', $this->clock->now()->modify('-1 hour')); $id = $p->getId();
        if ($ambito === 'establecimiento') { $this->local->setActivo(false); }
        else { $this->local->getEntidadFiscal()->setActivo(false); }
        $this->em->flush();
        $r = $this->comando();
        self::assertSame(0, $r['creadas']); self::assertSame(0, $r['vencidas']);
        self::assertSame('pendiente', $this->em->getConnection()->fetchOne('SELECT estado FROM tarea_programada WHERE id = ?', [$id]));
    }
    public static function ambitos(): array { return [['establecimiento'], ['fiscal']]; }

    public function testVencimientoPorLotesNoCambiaCerradasNiSinLimite(): void
    {
        $sinLimite = $this->programar('-3 hours');
        $igual = $this->programar('-2 hours', $this->clock->now());
        $completada = $this->programar('-4 hours', $this->clock->now()->modify('-1 hour'));
        $completada->completar($this->clock->now());
        $omitida = $this->programar('-5 hours', $this->clock->now()->modify('-1 hour'));
        $omitida->omitir('Cierre del establecimiento.', $this->usuario, $this->clock->now());
        $this->em->flush();
        $db = $this->em->getConnection();
        $db->executeStatement("INSERT INTO tarea_programada (tarea_id, establecimiento_id, fecha_programada, fecha_limite, created_at) SELECT ?, ?, TIMESTAMP '2026-09-01 00:00:00' + n * INTERVAL '1 second', TIMESTAMP '2026-09-01 01:00:00', TIMESTAMP '2026-09-01 00:00:00' FROM generate_series(1, 250) n", [$this->tarea->getId(), $this->local->getId()]);
        $service = self::getContainer()->get(TareaProgramadaService::class);
        self::assertSame(250, $service->detectarVencidas($this->local));
        self::assertSame(0, $service->detectarVencidas($this->local));
        self::assertSame(250, (int) $db->fetchOne("SELECT count(*) FROM tarea_programada WHERE estado = 'vencida'"));
        self::assertSame(EstadoTareaProgramada::PENDIENTE, $sinLimite->getEstado());
        self::assertSame(EstadoTareaProgramada::PENDIENTE, $igual->getEstado());
        self::assertSame(EstadoTareaProgramada::COMPLETADA, $completada->getEstado());
        self::assertSame(EstadoTareaProgramada::OMITIDA, $omitida->getEstado());
        self::assertLessThanOrEqual(4, count($this->em->getUnitOfWork()->getIdentityMap()[TareaProgramada::class] ?? []));
    }

    public function testErrorEnUnaTareaRevierteTodaSuVentanaYContinuaLasOtras(): void
    {
        $this->tareaEn($this->local, 'Otra tarea del local');
        $this->tareaEn($this->otroLocal, 'Otro establecimiento');
        $db = $this->em->getConnection();
        $db->executeStatement("CREATE FUNCTION appcc_test_fallo_ciclo() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.tarea_id = 1 AND NEW.fecha_programada::date = DATE '2026-09-14' THEN RAISE EXCEPTION 'Fallo operativo de prueba'; END IF; RETURN NEW; END \$\$");
        $db->executeStatement('CREATE TRIGGER appcc_test_fallo_ciclo BEFORE INSERT ON tarea_programada FOR EACH ROW EXECUTE FUNCTION appcc_test_fallo_ciclo()');
        try {
            $r = $this->comando(['--desde' => '2026-09-13', '--hasta' => '2026-09-15'], 1);
            self::assertSame(3, $r['tareasAnalizadas']);
            self::assertSame(6, $r['creadas']);
            self::assertCount(1, $r['errores']);
            self::assertSame('generacion', $r['errores'][0]['fase']);
            self::assertSame($this->local->getId(), $r['errores'][0]['establecimiento']);
            self::assertStringContainsString('Fallo operativo de prueba', $r['errores'][0]['mensaje']);
            self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM tarea_programada WHERE tarea_id = ?', [$this->tarea->getId()]));
        } finally {
            $db->executeStatement('DROP TRIGGER appcc_test_fallo_ciclo ON tarea_programada');
            $db->executeStatement('DROP FUNCTION appcc_test_fallo_ciclo()');
        }
        $r = $this->comando(['--desde' => '2026-09-13', '--hasta' => '2026-09-15']);
        self::assertSame(3, $r['creadas']); self::assertSame(6, $r['existentes']);
    }

    #[DataProvider('opcionesInvalidas')]
    public function testOpcionesInvalidasNoEscriben(array $opciones): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:tareas:procesar'));
        self::assertSame(2, $tester->execute($opciones));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tarea_programada'));
    }
    public static function opcionesInvalidas(): array
    {
        return [[['--horizonte-dias' => '0']], [['--horizonte-dias' => '-1']], [['--horizonte-dias' => '7.5']], [['--horizonte-dias' => '367']], [['--desde' => '2026-02-30']], [['--desde' => 'tomorrow']], [['--desde' => '2026-09-15', '--hasta' => '2026-09-14']], [['--desde' => '2026-09-14', '--hasta' => '2026-09-15T00:00:00Z']]];
    }

    public function testFalloDeUnLoteDeVencimientoConservaSuResumenYContinuaOtroEstablecimiento(): void
    {
        $this->tarea->setFrecuencia(FrecuenciaTarea::BAJO_DEMANDA);
        $otra = $this->tareaEn($this->otroLocal, 'Otra tarea');
        $otra->setFrecuencia(FrecuenciaTarea::BAJO_DEMANDA); $this->em->flush();
        $db = $this->em->getConnection();
        $db->executeStatement("INSERT INTO tarea_programada (tarea_id, establecimiento_id, fecha_programada, fecha_limite, created_at) SELECT ?, ?, TIMESTAMP '2026-09-01' + n * INTERVAL '1 second', TIMESTAMP '2026-09-02', TIMESTAMP '2026-09-01' FROM generate_series(1, 150) n", [$this->tarea->getId(), $this->local->getId()]);
        $db->executeStatement("INSERT INTO tarea_programada (tarea_id, establecimiento_id, fecha_programada, fecha_limite, created_at) VALUES (?, ?, '2026-09-01', '2026-09-02', '2026-09-01')", [$otra->getId(), $this->otroLocal->getId()]);
        $db->executeStatement("CREATE FUNCTION appcc_test_fallo_vencimiento() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.id = 101 AND NEW.estado = 'vencida' THEN RAISE EXCEPTION 'Fallo del segundo lote'; END IF; RETURN NEW; END \$\$");
        $db->executeStatement('CREATE TRIGGER appcc_test_fallo_vencimiento BEFORE UPDATE ON tarea_programada FOR EACH ROW EXECUTE FUNCTION appcc_test_fallo_vencimiento()');
        try {
            $r = $this->comando([], 1);
            self::assertSame(101, $r['vencidas']);
            self::assertCount(1, $r['errores']);
            self::assertSame('vencimiento', $r['errores'][0]['fase']);
            self::assertSame($this->local->getId(), $r['errores'][0]['establecimiento']);
            self::assertSame(50, (int) $db->fetchOne("SELECT count(*) FROM tarea_programada WHERE estado = 'pendiente'"));
            self::assertSame(101, (int) $db->fetchOne("SELECT count(*) FROM tarea_programada WHERE estado = 'vencida'"));
        } finally {
            $db->executeStatement('DROP TRIGGER appcc_test_fallo_vencimiento ON tarea_programada');
            $db->executeStatement('DROP FUNCTION appcc_test_fallo_vencimiento()');
        }
        self::assertSame(50, $this->comando()['vencidas']);
    }

    public function testLotesDentroDeTransaccionExteriorNoRepitenFilasYRespetanRollback(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement("INSERT INTO tarea_programada (tarea_id, establecimiento_id, fecha_programada, fecha_limite, created_at) SELECT ?, ?, TIMESTAMP '2026-09-01' + n * INTERVAL '1 second', TIMESTAMP '2026-09-02', TIMESTAMP '2026-09-01' FROM generate_series(1, 150) n", [$this->tarea->getId(), $this->local->getId()]);
        $db->beginTransaction();
        try {
            self::assertSame(150, self::getContainer()->get(TareaProgramadaService::class)->detectarVencidas($this->local));
            self::assertSame(0, self::getContainer()->get(TareaProgramadaService::class)->detectarVencidas($this->local));
            self::assertSame(150, (int) $db->fetchOne("SELECT count(*) FROM tarea_programada WHERE estado = 'vencida'"));
            self::assertLessThanOrEqual(1, count($this->em->getUnitOfWork()->getIdentityMap()[TareaProgramada::class] ?? []));
        } finally { $db->rollBack(); $this->em->clear(); }
        self::assertSame(150, (int) $db->fetchOne("SELECT count(*) FROM tarea_programada WHERE estado = 'pendiente'"));
    }
}
