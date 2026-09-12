<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añadir configuración temporal a las tareas APPCC recurrentes.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Esta migración requiere PostgreSQL.');
        $this->addSql('ALTER TABLE tarea_appcc ADD dia_semana INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tarea_appcc ADD dia_mes INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tarea_appcc ADD plazo_minutos INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Esta migración requiere PostgreSQL.');
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM tarea_appcc WHERE dia_semana IS NOT NULL OR dia_mes IS NOT NULL OR plazo_minutos IS NOT NULL) THEN RAISE EXCEPTION 'Reversión bloqueada: hay configuración temporal de tareas que requiere migración inversa explícita'; END IF; END $$");
        $this->addSql('ALTER TABLE tarea_appcc DROP dia_semana');
        $this->addSql('ALTER TABLE tarea_appcc DROP dia_mes');
        $this->addSql('ALTER TABLE tarea_appcc DROP plazo_minutos');
    }
}
