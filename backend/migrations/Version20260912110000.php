<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912110000 extends AbstractMigration
{
    private const CHECKS = [
        'chk_tarea_dia_semana' => '(dia_semana IS NULL OR dia_semana BETWEEN 1 AND 7) AND (frecuencia = \'semanal\' OR dia_semana IS NULL)',
        'chk_tarea_dia_mes' => '(dia_mes IS NULL OR dia_mes BETWEEN 1 AND 31) AND (frecuencia = \'mensual\' OR dia_mes IS NULL)',
        'chk_tarea_plazo' => 'plazo_minutos IS NULL OR plazo_minutos > 0',
        'chk_tarea_calendario' => "(frecuencia NOT IN ('diaria', 'semanal', 'mensual') OR hora_prevista IS NOT NULL) AND (frecuencia <> 'semanal' OR dia_semana IS NOT NULL) AND (frecuencia <> 'mensual' OR dia_mes IS NOT NULL)",
        'chk_tarea_hora' => "hora_prevista IS NULL OR hora_prevista < TIME '24:00:00'",
    ];

    public function getDescription(): string
    {
        return 'Proteger la configuración recurrente y detectar ediciones concurrentes de tareas.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Esta migración requiere PostgreSQL.');
        $this->addSql('ALTER TABLE tarea_appcc ADD version INT DEFAULT 1 NOT NULL');
        foreach (self::CHECKS as $nombre => $condicion) {
            // Conserva tareas heredadas incompletas; prohíbe nuevas escrituras inválidas.
            $this->addSql("ALTER TABLE tarea_appcc ADD CONSTRAINT $nombre CHECK ($condicion) NOT VALID");
            $this->addSql("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM tarea_appcc WHERE NOT ($condicion)) THEN ALTER TABLE tarea_appcc VALIDATE CONSTRAINT $nombre; END IF; END \$\$");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::CHECKS) as $nombre) {
            $this->addSql("ALTER TABLE tarea_appcc DROP CONSTRAINT $nombre");
        }
        $this->addSql('ALTER TABLE tarea_appcc DROP version');
    }
}
