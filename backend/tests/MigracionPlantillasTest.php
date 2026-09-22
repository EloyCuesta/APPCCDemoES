<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\PlantillaAPPCCService;
use App\Tests\Support\{PostgresTestCase, PostgresSafety};
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;

final class MigracionPlantillasTest extends PostgresTestCase
{
    private function migrar(string $direction): void
    {
        $db = $this->em->getConnection(); PostgresSafety::assertTestDatabase($db);
        require_once dirname(__DIR__).'/migrations/Version20260922090000.php';
        $migration = new \DoctrineMigrations\Version20260922090000($db, new NullLogger());
        $migration->$direction(new Schema());
        $db->transactional(function () use ($migration, $db): void {
            foreach ($migration->getSql() as $query) { $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes()); }
        });
    }

    public function testBackfillDeConfiguracionesExistentesYDefaultSql(): void
    {
        $db = $this->em->getConnection();
        $registro = $this->registrar();
        $antes = $db->fetchAssociative('SELECT * FROM registro_appcc WHERE id = ?', [$registro->getId()]);
        $this->migrar('down');
        try { $db->executeStatement('UPDATE configuracion_establecimiento SET requiere_foto_no_conforme = false'); }
        finally { $this->migrar('up'); $this->em->clear(); }
        self::assertSame(2, (int) $db->fetchOne('SELECT count(*) FROM configuracion_establecimiento WHERE requiere_foto_no_conforme'));
        self::assertSame($antes, $db->fetchAssociative('SELECT * FROM registro_appcc WHERE id = ?', [$registro->getId()]));
        self::assertSame('true', $db->fetchOne("SELECT column_default FROM information_schema.columns WHERE table_name = 'configuracion_establecimiento' AND column_name = 'requiere_foto_no_conforme'"));
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->expectExceptionMessage('chk_config_foto_obligatoria');
        $db->executeStatement('UPDATE configuracion_establecimiento SET requiere_foto_no_conforme = false');
    }

    public function testCheckImpideActivarControlSinLimitesPorSql(): void
    {
        $service = self::getContainer()->get(PlantillaAPPCCService::class);
        $p = $service->cargarIniciales()[1]; $r = $service->aplicar($p, $this->local);
        $id = $r['tareas'][0]->getId();
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->expectExceptionMessage('chk_tarea_limites_configurados');
        $this->em->getConnection()->executeStatement('UPDATE tarea_appcc SET activa = true WHERE id = ?', [$id]);
    }

    public function testRestriccionUnicaYReversionConservanAplicaciones(): void
    {
        $service = self::getContainer()->get(PlantillaAPPCCService::class);
        $service->aplicar($service->cargarIniciales()[1], $this->local);
        $db = $this->em->getConnection();
        try {
            $db->executeStatement('INSERT INTO aplicacion_plantilla_appcc (plantilla_id, establecimiento_id, resultado, created_at) SELECT plantilla_id, establecimiento_id, resultado, created_at FROM aplicacion_plantilla_appcc');
            self::fail('Debe rechazar duplicados.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) { self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM aplicacion_plantilla_appcc')); }
        try { $this->migrar('down'); self::fail('Debe conservar aplicaciones.'); }
        catch (\Doctrine\DBAL\Exception\DriverException $e) { self::assertStringContainsString('Reversión bloqueada', $e->getMessage()); }
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM aplicacion_plantilla_appcc'));
    }

    public function testFalloAlGuardarReciboRevierteTambienElFlushDeDefiniciones(): void
    {
        $service = self::getContainer()->get(PlantillaAPPCCService::class); $p = $service->cargarIniciales()[1];
        $db = $this->em->getConnection();
        $db->executeStatement('ALTER TABLE aplicacion_plantilla_appcc ADD CONSTRAINT test_recibo_falla CHECK (false)');
        try {
            try { $service->aplicar($p, $this->local); self::fail('Debe revertirse el recibo y los recursos.'); }
            catch (\Doctrine\DBAL\Exception\DriverException $e) { self::assertStringContainsString('test_recibo_falla', $e->getMessage()); }
            self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM plan_control'));
            self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM tarea_appcc'));
            self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM punto_control'));
            self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM aplicacion_plantilla_appcc'));
        } finally { $db->executeStatement('ALTER TABLE aplicacion_plantilla_appcc DROP CONSTRAINT test_recibo_falla'); }
    }
}
