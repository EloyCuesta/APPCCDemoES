<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260912084920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añadir autenticación a Usuario sin asignar contraseñas a cuentas existentes.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE usuario ADD password VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE usuario ADD roles JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM usuario WHERE password IS NOT NULL OR roles::text <> '[]') THEN RAISE EXCEPTION 'No se pueden descartar credenciales existentes sin una migración inversa explícita'; END IF; END $$");
        $this->addSql('ALTER TABLE usuario DROP password');
        $this->addSql('ALTER TABLE usuario DROP roles');
    }
}
