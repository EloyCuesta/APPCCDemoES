<?php
declare(strict_types=1);
namespace App\Tests;

use App\Tests\Support\{PostgresTestCase, PostgresSafety};
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaValidator;
use Psr\Log\NullLogger;

final class MigrationPlanTest extends PostgresTestCase
{
    private function executeMigration(string $version, string $direction): void
    {
        $db = $this->em->getConnection();
        PostgresSafety::assertTestDatabase($db);
        require_once dirname(__DIR__).'/migrations/'.$version.'.php';
        $class = 'DoctrineMigrations\\'.$version;
        $migration = new $class($db, new NullLogger());
        $migration->$direction(new Schema());
        $db->transactional(function () use ($migration, $db): void {
            foreach ($migration->getSql() as $query) { $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes()); }
        });
    }

    public function testIndicesChecksYForeignKeysReales(): void
    {
        $db = $this->em->getConnection();
        $indexes = $db->fetchAllKeyValue("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public'");
        foreach (['idx_programada_agenda', 'uniq_programada_tarea_fecha', 'idx_registro_local_fecha', 'idx_registro_usuario_fecha', 'idx_tarea_local_activa', 'idx_incidencia_agenda', 'idx_membresia_local_activo_usuario', 'uniq_incidencia_registro'] as $name) { self::assertArrayHasKey($name, $indexes); }
        self::assertStringContainsString('UNIQUE', $indexes['uniq_programada_tarea_fecha']);
        self::assertStringContainsString('WHERE (registro_id IS NOT NULL)', $indexes['uniq_incidencia_registro']);
        $checks = $db->fetchFirstColumn("SELECT conname FROM pg_constraint WHERE contype = 'c'");
        self::assertContains('chk_evidencia_parent', $checks);
        self::assertContains('chk_programada_completada', $checks);
        foreach (['chk_tarea_dia_semana', 'chk_tarea_dia_mes', 'chk_tarea_plazo', 'chk_tarea_calendario', 'chk_tarea_hora'] as $check) { self::assertContains($check, $checks); }
        self::assertSame(0, (int) $db->fetchOne("SELECT count(*) FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = 'public' AND c.contype = 'f' AND c.confdeltype <> 'r'"));
        self::assertSame([], array_values(array_diff((new SchemaValidator($this->em))->getUpdateSchemaList(), ['DROP TABLE doctrine_migration_versions'])));
    }

    public function testMigracionDesdeVacioDownYReaplicacion(): void
    {
        PostgresSafety::truncate($this->em->getConnection());
        $this->em->clear();
        $versions = array_map(static fn ($f) => basename($f, '.php'), glob(dirname(__DIR__).'/migrations/Version*.php'));
        foreach (array_reverse($versions) as $version) { $this->executeMigration($version, 'down'); }
        self::assertSame(['doctrine_migration_versions'], $this->em->getConnection()->createSchemaManager()->listTableNames());
        foreach ($versions as $version) { $this->executeMigration($version, 'up'); }
        self::assertSame([], array_values(array_diff((new SchemaValidator($this->em))->getUpdateSchemaList(), ['DROP TABLE doctrine_migration_versions'])));
    }

    public function testBackfillConservaRegistroYOrigen(): void
    {
        // Retroceder primero las ampliaciones de tarea_programada antes de recrear su tabla.
        $this->executeMigration('Version20260916090000', 'down');
        $this->executeMigration('Version20260913100000', 'down');
        $this->executeMigration('Version20260912083224', 'down');
        $db = $this->em->getConnection();
        try {
            $id = $db->fetchOne("INSERT INTO registro_appcc (tarea_id, establecimiento_id, usuario_id, fecha_hora, conforme, valor_numerico, created_at) VALUES (?, ?, ?, '2025-06-01 12:00:00', false, 9.125, '2025-06-01 12:00:00') RETURNING id", [$this->tarea->getId(), $this->local->getId(), $this->usuario->getId()]);
            $this->executeMigration('Version20260912083224', 'up');
            $row = $db->fetchAssociative('SELECT r.valor_numerico, p.* FROM registro_appcc r JOIN tarea_programada p ON p.id = r.tarea_programada_id WHERE r.id = ?', [$id]);
            self::assertSame('completada', $row['estado']);
            self::assertSame('2025-06-01 12:00:00', $row['fecha_programada']);
            self::assertSame($row['fecha_programada'], $row['completada_at']);
            self::assertSame($this->tarea->getId(), $row['tarea_id']);
            self::assertSame($this->local->getId(), $row['establecimiento_id']);
            self::assertSame($this->usuario->getId(), $row['asignado_a_id']);
            self::assertSame('9.125', $row['valor_numerico']);
        } finally {
            $this->executeMigration('Version20260913100000', 'up');
            $this->executeMigration('Version20260916090000', 'up');
            $this->em->clear();
        }
    }

    public function testDownRechazaPerdidaDeDatos(): void
    {
        $this->registrar('9');
        try { $this->executeMigration('Version20260912083224', 'down'); self::fail('No debe borrar históricos.'); }
        catch (\Doctrine\DBAL\Exception\DriverException $e) { self::assertStringContainsString('Reversión bloqueada', $e->getMessage()); }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM registro_appcc'));
    }

    public function testChecksImpidenCalendariosInvalidosPorSql(): void
    {
        $db = $this->em->getConnection();
        foreach (["dia_semana = 0", "dia_semana = 8", "dia_semana = 1", "dia_mes = 0", "dia_mes = 32", "dia_mes = 1", "plazo_minutos = 0", "plazo_minutos = -1", "hora_prevista = NULL", "hora_prevista = '24:00:00'", "frecuencia = 'semanal'", "frecuencia = 'mensual'"] as $asignacion) {
            $db->beginTransaction();
            try {
                $db->executeStatement('UPDATE tarea_appcc SET '.$asignacion.' WHERE id = ?', [$this->tarea->getId()]);
                self::fail('PostgreSQL aceptó '.$asignacion);
            } catch (\Doctrine\DBAL\Exception\DriverException $e) {
                self::assertSame('23514', $e->getSQLState(), $asignacion);
            } finally { $db->rollBack(); }
        }
    }

    public function testMigracionPreservaTareaHeredadaIncompletaPeroBloqueaNuevasEscriturasInvalidas(): void
    {
        $db = $this->em->getConnection();
        $this->executeMigration('Version20260912110000', 'down');
        $db->executeStatement('UPDATE tarea_appcc SET hora_prevista = NULL WHERE id = ?', [$this->tarea->getId()]);
        $this->executeMigration('Version20260912110000', 'up');
        $this->em->clear();
        self::assertFalse($db->fetchOne("SELECT convalidated FROM pg_constraint WHERE conname = 'chk_tarea_calendario'"));
        $r = self::getContainer()->get(\App\Service\GeneradorTareasProgramadasService::class)->generar(new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15'), true);
        self::assertSame(1, $r->ignoradas);
        self::assertStringContainsString('hora prevista', $r->ignoradasDetalle[0]);
        self::assertSame(0, $r->creadas);
        // Reparación explícita, sin inventar datos durante la migración.
        $db->executeStatement("UPDATE tarea_appcc SET hora_prevista = '09:00:00' WHERE id = ?", [$this->tarea->getId()]);
        $db->executeStatement('ALTER TABLE tarea_appcc VALIDATE CONSTRAINT chk_tarea_calendario');
        self::assertTrue($db->fetchOne("SELECT convalidated FROM pg_constraint WHERE conname = 'chk_tarea_calendario'"));
    }
}
