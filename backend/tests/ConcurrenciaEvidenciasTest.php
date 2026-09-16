<?php

declare(strict_types=1);

namespace App\Tests;

use App\Enum\TipoEvidencia;
use App\Service\SubidaEvidenciaService;
use App\Tests\Support\{EvidenciaFixtures, PostgresTestCase};
use Symfony\Component\Process\Process;

final class ConcurrenciaEvidenciasTest extends PostgresTestCase
{
    private array $workers = [];

    public function testDosRegistrosConcurrentesSoloConsumenUnaVez(): void
    {
        $this->usuario->setPassword('hash-solo-fixture'); $this->em->flush();
        self::getContainer()->get('router')->match('/api/registros');
        $token = self::getContainer()->get(SubidaEvidenciaService::class)->subir(EvidenciaFixtures::archivo(), TipoEvidencia::FOTO, $this->usuario, $this->local)['token'];
        $p1 = $this->programar(); $p2 = $this->programar('-2 minutes');
        $db = $this->em->getConnection(); $db->beginTransaction();
        $db->fetchOne('SELECT id FROM subida_temporal_evidencia FOR UPDATE');
        foreach ([$p1, $p2] as $i => $p) {
            $input = ['uri' => '/api/registros', 'autor' => $this->usuario->getId(), 'local' => $this->local->getId(), 'data' => [
                'tareaProgramada' => '/api/tareas-programadas/'.$p->getId(), 'valorNumerico' => '3', 'confirmarRegistro' => true, 'evidencias' => [['token' => $token, 'tipo' => 'foto']]]];
            $worker = new Process([PHP_BINARY, __DIR__.'/Support/usuarios-worker.php', 'appcc_evidencias_'.$i], dirname(__DIR__), ['APP_ENV' => 'test', 'SYMFONY_DOTENV_VARS' => false, 'SHELL_VERBOSITY' => '-1', 'APPCC_EVIDENCIAS_DIR' => $this->evidenciasDir], json_encode($input, JSON_THROW_ON_ERROR));
            $worker->setTimeout(35); $worker->start(); $this->workers[] = $worker;
        }
        $limite = microtime(true) + 20;
        do {
            $db->executeQuery('SELECT pg_stat_clear_snapshot()');
            $esperando = (int) $db->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name LIKE 'appcc_evidencias_%' AND wait_event_type = 'Lock'");
            if ($esperando === 2) { break; }
            usleep(20000);
        } while (microtime(true) < $limite);
        self::assertSame(2, $esperando, implode('\n', array_map(static fn ($w) => $w->getOutput().$w->getErrorOutput(), $this->workers)));
        $db->commit();
        $codigos = [];
        foreach ($this->workers as $w) { self::assertSame(0, $w->wait(), $w->getErrorOutput()); $codigos[] = json_decode($w->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status']; }
        sort($codigos); self::assertSame([201, 422], $codigos, implode('\n', array_map(static fn ($w) => $w->getErrorOutput(), $this->workers)));
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM evidencia'));
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM registro_appcc'));
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM subida_temporal_evidencia WHERE consumida_at IS NOT NULL'));
        self::assertSame(1, (int) $db->fetchOne("SELECT count(*) FROM tarea_programada WHERE estado = 'completada'"));
        self::assertCount(1, glob($this->evidenciasDir.'/definitivo/*'));
        self::assertSame([], glob($this->evidenciasDir.'/temporal/*'));
    }

    public function testLimpiezaOmiteSubidasBloqueadasPorUnRegistro(): void
    {
        self::getContainer()->get(SubidaEvidenciaService::class)->subir(EvidenciaFixtures::archivo(), TipoEvidencia::FOTO, $this->usuario, $this->local);
        // Caducidad explícita, independiente de la fecha real de la máquina del CI.
        $db = $this->em->getConnection();
        $db->executeStatement("UPDATE subida_temporal_evidencia SET expires_at = '2000-01-01'");
        $db->beginTransaction(); $db->fetchOne('SELECT id FROM subida_temporal_evidencia FOR UPDATE');
        $worker = new Process([PHP_BINARY, 'bin/console', 'app:evidencias:limpiar-temporales', '--env=test'], dirname(__DIR__), ['APP_ENV' => 'test', 'SYMFONY_DOTENV_VARS' => false, 'SHELL_VERBOSITY' => '0', 'APPCC_EVIDENCIAS_DIR' => $this->evidenciasDir]);
        $worker->setTimeout(25); $worker->start(); $this->workers[] = $worker;
        self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
        self::assertStringContainsString('eliminadas: 0', $worker->getOutput());
        self::assertCount(1, glob($this->evidenciasDir.'/temporal/*'));
        $db->commit();
        self::assertSame(1, self::getContainer()->get(SubidaEvidenciaService::class)->limpiarCaducadas());
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) { $this->em->getConnection()->rollBack(); }
        foreach ($this->workers as $w) { if ($w->isRunning()) { $w->stop(0); } }
        parent::tearDown();
    }
}
