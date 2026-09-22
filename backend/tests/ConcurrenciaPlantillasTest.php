<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\PlantillaAPPCCService;
use App\Tests\Support\PostgresTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

final class ConcurrenciaPlantillasTest extends PostgresTestCase
{
    private array $workers = [];

    #[DataProvider('revocacion')]
    public function testAplicacionesSimultaneasYRevalidacionTrasBloqueo(bool $revocar): void
    {
        $this->usuario->setPassword('hash-solo-fixture'); $this->em->flush();
        $plantillas = self::getContainer()->get(PlantillaAPPCCService::class)->cargarIniciales();
        $uri = '/api/plantillas-appcc/'.$plantillas[1]->getId().'/aplicar';
        self::getContainer()->get('router')->match('/api/plantillas-appcc');
        $db = $this->em->getConnection(); $db->beginTransaction();
        $db->fetchOne('SELECT id FROM establecimiento WHERE id = ? FOR UPDATE', [$this->local->getId()]);
        foreach ([0, 1] as $i) {
            $input = ['uri' => $uri, 'autor' => $this->usuario->getId(), 'local' => $this->local->getId()];
            $worker = new Process([PHP_BINARY, __DIR__.'/Support/usuarios-worker.php', 'appcc_plantilla_'.$i], dirname(__DIR__),
                ['APP_ENV' => 'test', 'SYMFONY_DOTENV_VARS' => false, 'SHELL_VERBOSITY' => '-1'], json_encode($input, JSON_THROW_ON_ERROR));
            $worker->setTimeout(40); $worker->start(); $this->workers[] = $worker;
        }
        $limite = microtime(true) + 25;
        do {
            $db->executeQuery('SELECT pg_stat_clear_snapshot()');
            $esperando = (int) $db->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name LIKE 'appcc_plantilla_%' AND wait_event_type = 'Lock'");
            if ($esperando === 2) { break; }
            usleep(20000);
        } while (microtime(true) < $limite);
        self::assertSame(2, $esperando, implode('\n', array_map(fn ($w) => $w->getOutput().$w->getErrorOutput(), $this->workers)));
        if ($revocar) {
            $db->executeStatement("UPDATE usuario_establecimiento SET rol = 'auditor' WHERE usuario_id = ? AND establecimiento_id = ?", [$this->usuario->getId(), $this->local->getId()]);
        }
        $db->commit();
        foreach ($this->workers as $w) {
            self::assertSame(0, $w->wait(), $w->getErrorOutput());
            self::assertSame($revocar ? 403 : 200, json_decode($w->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'], $w->getErrorOutput());
        }
        self::assertSame($revocar ? 0 : 1, (int) $db->fetchOne('SELECT count(*) FROM aplicacion_plantilla_appcc'));
        self::assertSame($revocar ? 1 : 7, (int) $db->fetchOne('SELECT count(*) FROM plan_control'));
        self::assertSame($revocar ? 0 : 4, (int) $db->fetchOne('SELECT count(*) FROM punto_control'));
        self::assertSame($revocar ? 1 : 8, (int) $db->fetchOne('SELECT count(*) FROM tarea_appcc'));
        self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM tarea_appcc WHERE establecimiento_id = ?', [$this->otroLocal->getId()]));
    }
    public static function revocacion(): array { return [[false], [true]]; }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) { $this->em->getConnection()->rollBack(); }
        foreach ($this->workers as $w) { if ($w->isRunning()) { $w->stop(0); } }
        parent::tearDown();
    }
}
