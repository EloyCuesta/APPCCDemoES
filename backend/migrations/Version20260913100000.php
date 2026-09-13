<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913100000 extends AbstractMigration
{
    // Mismo recorte ASCII que trim() en PHP; PostgreSQL no admite NUL en TEXT.
    private const MOTIVO_RECORTADO = "btrim(motivo_omision, ' ' || chr(9) || chr(10) || chr(13) || chr(11))";
    private const OMISION = "(estado = 'omitida' AND motivo_omision IS NOT NULL AND char_length(".self::MOTIVO_RECORTADO.") BETWEEN 3 AND 2000 AND motivo_omision = ".self::MOTIVO_RECORTADO." AND omitida_at IS NOT NULL AND omitida_por_id IS NOT NULL) OR (estado <> 'omitida' AND motivo_omision IS NULL AND omitida_at IS NULL AND omitida_por_id IS NULL)";

    public function getDescription(): string { return 'Auditar omisiones sin inventar autores históricos e indexar vencimientos por fecha límite.'; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Esta migración requiere PostgreSQL.');
        $this->addSql('ALTER TABLE tarea_programada ADD motivo_omision TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE tarea_programada ADD omitida_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE tarea_programada ADD omitida_por_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_programada_omitida_por ON tarea_programada (omitida_por_id)');
        $this->addSql('ALTER TABLE tarea_programada ADD CONSTRAINT fk_programada_omitida_por FOREIGN KEY (omitida_por_id) REFERENCES usuario (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $condicion = self::OMISION;
        // NOT VALID conserva filas OMITIDA heredadas sin fabricar motivo, hora ni autor.
        // PostgreSQL sí exige el CHECK en toda nueva inserción o actualización.
        $this->addSql("ALTER TABLE tarea_programada ADD CONSTRAINT chk_programada_omision CHECK ($condicion) NOT VALID");
        $this->addSql("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM tarea_programada WHERE NOT ($condicion)) THEN ALTER TABLE tarea_programada VALIDATE CONSTRAINT chk_programada_omision; END IF; END \$\$");
        $this->addSql('CREATE INDEX idx_programada_vencimiento ON tarea_programada (establecimiento_id, estado, fecha_limite, id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Esta migración requiere PostgreSQL.');
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM tarea_programada WHERE motivo_omision IS NOT NULL OR omitida_at IS NOT NULL OR omitida_por_id IS NOT NULL) THEN RAISE EXCEPTION 'Reversión bloqueada: hay auditoría de omisión que requiere migración inversa explícita'; END IF; END \$\$");
        $this->addSql('DROP INDEX idx_programada_vencimiento');
        $this->addSql('ALTER TABLE tarea_programada DROP CONSTRAINT chk_programada_omision');
        $this->addSql('ALTER TABLE tarea_programada DROP CONSTRAINT fk_programada_omitida_por');
        $this->addSql('DROP INDEX idx_programada_omitida_por');
        $this->addSql('ALTER TABLE tarea_programada DROP motivo_omision, DROP omitida_at, DROP omitida_por_id');
    }
}
