<?php
declare(strict_types=1);
namespace App\Tests;

use App\Tests\Support\UsuariosApiTestCase;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;

final class IntegridadUsuariosTest extends UsuariosApiTestCase
{
    public function testRestriccionesPostgresImpidenDuplicadosYEstadosIncoherentes(): void
    {
        $i = $this->invitar(); $db = $this->em->getConnection();
        $sqls = [
            ["UPDATE usuario SET email = 'ANA@example.com' WHERE id = 1", '23514'],
            ["UPDATE invitacion_usuario SET email = 'NUEVA@example.com'", '23514'],
            ["UPDATE invitacion_usuario SET estado = 'aceptada'", '23514'],
            ["UPDATE invitacion_usuario SET estado = 'otro'", '23514'],
            ["UPDATE invitacion_usuario SET rol = 'superadmin'", '23514'],
            ["UPDATE invitacion_usuario SET token_hash = 'secreto-en-claro'", '23514'],
            ["INSERT INTO invitacion_usuario (email, rol, token_hash, estado, expires_at, created_at, establecimiento_id, invitada_por_id) SELECT email, rol, repeat('f', 64), estado, expires_at, created_at, establecimiento_id, invitada_por_id FROM invitacion_usuario", '23505'],
            ['INSERT INTO usuario_establecimiento (usuario_id, establecimiento_id, rol, activo) SELECT usuario_id, establecimiento_id, rol, activo FROM usuario_establecimiento WHERE id = 1', '23505'],
        ];
        foreach ($sqls as [$sql, $state]) {
            $db->beginTransaction();
            try { $db->executeStatement($sql); self::fail('PostgreSQL debe rechazar la escritura.'); }
            catch (\Doctrine\DBAL\Exception\DriverException $e) { self::assertSame($state, $e->getSQLState()); }
            finally { $db->rollBack(); }
        }
        self::assertSame('pendiente', $db->fetchOne('SELECT estado FROM invitacion_usuario WHERE id = ?', [$i['id']]));
    }

    public function testRollbackAceptacionSiFallaEmisionDePassword(): void
    {
        $i = $this->invitar(); $db = $this->em->getConnection();
        $db->executeStatement('ALTER TABLE token_configuracion_password ADD CONSTRAINT test_aceptacion_falla CHECK (false)');
        try {
            $r = $this->request('POST', '/api/invitaciones/aceptar', ['token' => $i['token'], 'nombre' => 'Nueva', 'apellidos' => 'Persona']);
            self::assertSame(500, $r->getStatusCode());
            self::assertSame('pendiente', $db->fetchOne('SELECT estado FROM invitacion_usuario WHERE id = ?', [$i['id']]));
            self::assertNull($db->fetchOne('SELECT accepted_at FROM invitacion_usuario WHERE id = ?', [$i['id']]));
            foreach (['usuario', 'usuario_establecimiento'] as $t) { self::assertSame(2, (int) $db->fetchOne('SELECT count(*) FROM '.$t)); }
        } finally { $db->executeStatement('ALTER TABLE token_configuracion_password DROP CONSTRAINT test_aceptacion_falla'); }
    }

    public function testRollbackPasswordSiFallaConsumoYDownNoBorraCredenciales(): void
    {
        $r = $this->request('POST', '/api/onboarding', self::onboarding()); self::assertSame(201, $r->getStatusCode());
        $data = $this->json($r); $db = $this->em->getConnection();
        $db->executeStatement('ALTER TABLE token_configuracion_password ADD CONSTRAINT test_consumo_falla CHECK (consumed_at IS NULL)');
        try {
            self::assertSame(500, $this->request('POST', '/api/auth/configurar-password', ['token' => $data['tokenConfiguracionPassword'], 'password' => self::PASSWORD, 'passwordConfirmation' => self::PASSWORD])->getStatusCode());
            self::assertNull($db->fetchOne('SELECT password FROM usuario WHERE id = ?', [$data['usuarioId']]));
            self::assertNull($db->fetchOne('SELECT consumed_at FROM token_configuracion_password'));
        } finally { $db->executeStatement('ALTER TABLE token_configuracion_password DROP CONSTRAINT test_consumo_falla'); }
        require_once dirname(__DIR__).'/migrations/Version20260913205925.php';
        $migration = new \DoctrineMigrations\Version20260913205925($db, new NullLogger());
        $migration->down(new Schema());
        $db->beginTransaction();
        try {
            foreach ($migration->getSql() as $sql) { $db->executeStatement($sql->getStatement()); }
            self::fail('down debe conservar tokens.');
        } catch (\Doctrine\DBAL\Exception\DriverException $e) { self::assertStringContainsString('Reversión bloqueada', $e->getMessage()); }
        finally { $db->rollBack(); }
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM token_configuracion_password'));
    }

    public function testTokenAdicionalNoSobrescribePasswordYaConfigurada(): void
    {
        $i = $this->invitar('luis@example.com');
        $db = $this->em->getConnection(); $db->executeStatement('UPDATE usuario SET password = NULL WHERE id = ?', [$this->otroUsuario->getId()]);
        $r = $this->request('POST', '/api/invitaciones/aceptar', ['token' => $i['token']]);
        self::assertSame(200, $r->getStatusCode(), $r->getContent()); $data = $this->json($r);
        // Otro token de alta para la misma identidad, como dos invitaciones a diferentes empresas.
        $u = $this->em->find(\App\Entity\Usuario::class, $this->otroUsuario->getId());
        [$segundo] = self::getContainer()->get(\App\Service\Support\TransaccionAPPCC::class)->ejecutar(fn () => self::getContainer()->get(\App\Service\PasswordInicialService::class)->emitir($u));
        foreach ([[$data['tokenConfiguracionPassword'], 204], [$segundo, 409]] as [$token, $status]) {
            self::assertSame($status, $this->request('POST', '/api/auth/configurar-password', ['token' => $token, 'password' => self::PASSWORD, 'passwordConfirmation' => self::PASSWORD])->getStatusCode());
        }
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM token_configuracion_password WHERE consumed_at IS NOT NULL'));
    }
}
