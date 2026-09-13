<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\TareaProgramada;
use App\Enum\EstadoTareaProgramada;
use App\Exception\BusinessRuleException;
use App\Tests\Support\{PostgresTestCase, PostgresSafety};
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaValidator;
use Psr\Log\NullLogger;

final class MigracionCicloOperativoTest extends PostgresTestCase
{
    private function migrar(string $direction): void
    {
        $db = $this->em->getConnection();
        PostgresSafety::assertTestDatabase($db);
        require_once dirname(__DIR__).'/migrations/Version20260913100000.php';
        $migration = new \DoctrineMigrations\Version20260913100000($db, new NullLogger());
        $migration->$direction(new Schema());
        $db->transactional(function () use ($db, $migration): void {
            foreach ($migration->getSql() as $query) { $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes()); }
        });
    }

    public function testRestriccionesSqlRechazanOmisionIncompletaYAuditoriaEnOtroEstado(): void
    {
        $p = $this->programar(); $db = $this->em->getConnection();
        $casos = ["estado = 'omitida'", "motivo_omision = 'Cierre'", "omitida_at = CURRENT_TIMESTAMP", "omitida_por_id = ".$this->usuario->getId(),
            "estado = 'omitida', motivo_omision = '  ', omitida_at = CURRENT_TIMESTAMP, omitida_por_id = ".$this->usuario->getId(),
            "estado = 'omitida', motivo_omision = ' Cierre ', omitida_at = CURRENT_TIMESTAMP, omitida_por_id = ".$this->usuario->getId(),
            "estado = 'omitida', motivo_omision = repeat(chr(9), 5), omitida_at = CURRENT_TIMESTAMP, omitida_por_id = ".$this->usuario->getId(),
            "estado = 'omitida', motivo_omision = chr(10) || 'Cierre', omitida_at = CURRENT_TIMESTAMP, omitida_por_id = ".$this->usuario->getId(),
            "estado = 'omitida', motivo_omision = repeat('a', 2001), omitida_at = CURRENT_TIMESTAMP, omitida_por_id = ".$this->usuario->getId()];
        foreach ($casos as $sql) {
            $db->beginTransaction();
            try { $db->executeStatement('UPDATE tarea_programada SET '.$sql.' WHERE id = ?', [$p->getId()]); self::fail('La base aceptó una omisión incoherente.'); }
            catch (\Doctrine\DBAL\Exception\DriverException $e) { self::assertSame('23514', $e->getSQLState()); }
            finally { $db->rollBack(); }
        }
        $p->omitir('Cierre del local.', $this->usuario, $this->clock->now()); $this->em->flush();
        self::assertSame('omitida', $db->fetchOne('SELECT estado FROM tarea_programada WHERE id = ?', [$p->getId()]));
        self::assertTrue($db->fetchOne("SELECT convalidated FROM pg_constraint WHERE conname = 'chk_programada_omision'"));
    }

    public function testMigracionConservaOmisionHeredadaSinInventarAutorNiFecha(): void
    {
        $db = $this->em->getConnection();
        $this->migrar('down');
        $id = $db->fetchOne("INSERT INTO tarea_programada (tarea_id, establecimiento_id, fecha_programada, estado, created_at) VALUES (?, ?, '2025-01-01 10:00:00', 'omitida', '2025-01-01 09:00:00') RETURNING id", [$this->tarea->getId(), $this->local->getId()]);
        $antes = $db->fetchAssociative('SELECT * FROM tarea_programada WHERE id = ?', [$id]);
        $this->migrar('up'); $this->em->clear();
        $despues = $db->fetchAssociative('SELECT * FROM tarea_programada WHERE id = ?', [$id]);
        foreach ($antes as $campo => $valor) { self::assertSame($valor, $despues[$campo]); }
        foreach (['omitida_por_id', 'omitida_at', 'motivo_omision'] as $campo) { self::assertNull($despues[$campo]); }
        self::assertFalse($db->fetchOne("SELECT convalidated FROM pg_constraint WHERE conname = 'chk_programada_omision'"));
        $db->beginTransaction();
        try {
            $db->executeStatement("INSERT INTO tarea_programada (tarea_id, establecimiento_id, fecha_programada, estado, created_at) VALUES (?, ?, '2025-01-02 10:00:00', 'omitida', '2025-01-02 09:00:00')", [$this->tarea->getId(), $this->local->getId()]);
            self::fail('NOT VALID debe seguir impidiendo omisiones nuevas sin auditoría.');
        } catch (\Doctrine\DBAL\Exception\DriverException $e) { self::assertSame('23514', $e->getSQLState()); }
        finally { $db->rollBack(); }
        self::assertSame([], array_values(array_diff((new SchemaValidator($this->em))->getUpdateSchemaList(), ['DROP TABLE doctrine_migration_versions'])));
        // Restaurar validación después de retirar únicamente el fixture de prueba heredado.
        $db->executeStatement('DELETE FROM tarea_programada WHERE id = ?', [$id]);
        $db->executeStatement('ALTER TABLE tarea_programada VALIDATE CONSTRAINT chk_programada_omision');
    }

    public function testReversionBloqueaPerdidaDeAuditoria(): void
    {
        $p = $this->programar();
        $p->omitir('Cierre del local.', $this->usuario, $this->clock->now()); $this->em->flush();
        try { $this->migrar('down'); self::fail('La reversión debe preservar la auditoría.'); }
        catch (\Doctrine\DBAL\Exception\DriverException $e) { self::assertStringContainsString('Reversión bloqueada', $e->getMessage()); }
        self::assertSame('Cierre del local.', $this->em->getConnection()->fetchOne('SELECT motivo_omision FROM tarea_programada WHERE id = ?', [$p->getId()]));
    }

    public function testDominioImpideReabrirOOmitirSinAuditoria(): void
    {
        $p = $this->programar();
        try { $p->cambiarEstado(EstadoTareaProgramada::OMITIDA); self::fail('Se requiere auditoría.'); }
        catch (BusinessRuleException) { self::assertSame(EstadoTareaProgramada::PENDIENTE, $p->getEstado()); }
        $p->omitir('Cierre del local.', $this->usuario, $this->clock->now());
        foreach ([EstadoTareaProgramada::PENDIENTE, EstadoTareaProgramada::VENCIDA, EstadoTareaProgramada::COMPLETADA, EstadoTareaProgramada::OMITIDA] as $estado) {
            try { $p->cambiarEstado($estado); self::fail('Una omisión es definitiva.'); }
            catch (BusinessRuleException) { self::assertSame(EstadoTareaProgramada::OMITIDA, $p->getEstado()); }
        }
    }

    public function testPlanDeConsultaJustificaIndicePorFechaLimite(): void
    {
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            $db->executeStatement("INSERT INTO tarea_programada (tarea_id, establecimiento_id, fecha_programada, fecha_limite, created_at) SELECT ?, ?, TIMESTAMP '2026-01-01' + n * INTERVAL '1 minute', CASE WHEN n <= 20 THEN TIMESTAMP '2026-09-11' ELSE TIMESTAMP '2027-01-01' END, TIMESTAMP '2026-01-01' FROM generate_series(1, 20000) n", [$this->tarea->getId(), $this->local->getId()]);
            $db->executeStatement('ANALYZE tarea_programada');
            $sql = "EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT id FROM tarea_programada WHERE establecimiento_id = ? AND estado = 'pendiente' AND fecha_limite < TIMESTAMP '2026-09-12 10:00:00' ORDER BY fecha_limite, id LIMIT 100 FOR UPDATE";
            $con = $db->fetchOne($sql, [$this->local->getId()]);
            self::assertStringContainsString('idx_programada_vencimiento', $con);
            $plan = json_decode($con, true, flags: JSON_THROW_ON_ERROR)[0]['Plan'];
            self::assertSame(20, (int) $plan['Actual Rows']);
            $db->executeStatement('DROP INDEX idx_programada_vencimiento');
            $sin = $db->fetchOne($sql, [$this->local->getId()]);
            self::assertStringContainsString('Rows Removed by Filter', $sin);
            self::assertStringContainsString('19980', $sin);
            file_put_contents(dirname(__DIR__).'/var/plan-vencimientos.json', json_encode(['con_indice' => json_decode($con, true), 'sin_indice' => json_decode($sin, true)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally { $db->rollBack(); }
    }
}
