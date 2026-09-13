<?php
declare(strict_types=1);
namespace App\Tests;

use App\Entity\Usuario;
use App\Tests\Support\UsuariosApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OnboardingPasswordTest extends UsuariosApiTestCase
{
    public function testOnboardingCompletoYLoginAntesYDespues(): void
    {
        $r = $this->request('POST', '/api/onboarding', self::onboarding());
        self::assertSame(201, $r->getStatusCode(), $r->getContent());
        self::assertTrue($r->headers->hasCacheControlDirective('no-store'));
        $data = $this->json($r); $db = $this->em->getConnection();
        foreach (['entidad_fiscal', 'configuracion_entidad_fiscal', 'establecimiento', 'configuracion_establecimiento', 'usuario', 'usuario_establecimiento'] as $table) {
            self::assertSame(3, (int) $db->fetchOne('SELECT count(*) FROM '.$table), $table);
        }
        $member = $db->fetchAssociative('SELECT * FROM usuario_establecimiento WHERE id = ?', [$data['membresiaId']]);
        self::assertSame('admin', $member['rol']); self::assertTrue($member['activo']);
        self::assertSame($data['usuarioId'], $member['usuario_id']); self::assertSame($data['establecimientoId'], $member['establecimiento_id']);
        $token = $data['tokenConfiguracionPassword'];
        self::assertSame(64, strlen($token));
        $fila = $db->fetchAssociative('SELECT * FROM token_configuracion_password');
        self::assertSame(hash('sha256', $token), $fila['token_hash']);
        self::assertStringNotContainsString($token, json_encode($fila));
        self::assertNull($db->fetchOne('SELECT password FROM usuario WHERE id = ?', [$data['usuarioId']]));
        self::assertSame(401, $this->request('POST', '/api/login_check', ['email' => 'maria@example.com', 'password' => self::PASSWORD])->getStatusCode());
        self::assertSame(204, $this->configurar($token)->getStatusCode());
        $u = $this->em->find(Usuario::class, $data['usuarioId']);
        self::assertNotSame(self::PASSWORD, $u->getPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($u, self::PASSWORD));
        self::assertNotNull($db->fetchOne('SELECT consumed_at FROM token_configuracion_password'));
        self::assertSame(200, $this->request('POST', '/api/login_check', ['email' => ' MARIA@EXAMPLE.COM ', 'password' => self::PASSWORD])->getStatusCode());
        self::assertSame(409, $this->configurar($token)->getStatusCode());
    }

    #[DataProvider('duplicados')]
    public function testOnboardingDuplicadoNoVinculaNiCreaDatos(string $campo, string $valor): void
    {
        $input = self::onboarding();
        if ($campo === 'nif') { $input['entidadFiscal']['nif'] = $valor; } else { $input['administrador']['email'] = $valor; }
        self::assertSame(409, $this->request('POST', '/api/onboarding', $input)->getStatusCode());
        foreach (['entidad_fiscal', 'establecimiento', 'usuario', 'usuario_establecimiento'] as $table) { self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM '.$table)); }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM token_configuracion_password'));
    }
    public static function duplicados(): array { return [['nif', ' b12345678 '], ['email', ' ANA@EXAMPLE.COM ']]; }

    public function testRollbackDeOnboardingAnteFalloFinalEnPostgres(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement("ALTER TABLE token_configuracion_password ADD CONSTRAINT test_fallo_alta CHECK (false)");
        try {
            self::assertSame(500, $this->request('POST', '/api/onboarding', self::onboarding())->getStatusCode());
            foreach (['entidad_fiscal', 'configuracion_entidad_fiscal', 'establecimiento', 'configuracion_establecimiento', 'usuario', 'usuario_establecimiento'] as $t) { self::assertSame(2, (int) $db->fetchOne('SELECT count(*) FROM '.$t), $t); }
        } finally { $db->executeStatement('ALTER TABLE token_configuracion_password DROP CONSTRAINT test_fallo_alta'); }
    }

    #[DataProvider('camposProhibidos')]
    public function testOnboardingRechazaMassAssignment(string $grupo, string $campo, mixed $valor): void
    {
        $input = self::onboarding(); $input[$grupo][$campo] = $valor;
        self::assertSame(400, $this->request('POST', '/api/onboarding', $input)->getStatusCode());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM usuario'));
    }
    public static function camposProhibidos(): array { return [['administrador', 'rol', 'auditor'], ['administrador', 'password', 'insegura'], ['administrador', 'activo', false], ['entidadFiscal', 'id', 1], ['establecimiento', 'entidadFiscalId', 1], ['establecimiento', 'createdAt', '2020-01-01']]; }

    #[DataProvider('passwordInvalido')]
    public function testPasswordRechazadaSinConsumir(string $caso, int $status): void
    {
        $r = $this->request('POST', '/api/onboarding', self::onboarding());
        self::assertSame(201, $r->getStatusCode(), $r->getContent());
        $data = $this->json($r); $token = $data['tokenConfiguracionPassword']; $db = $this->em->getConnection();
        if ($caso === 'expirado') { $db->executeStatement("UPDATE token_configuracion_password SET expires_at = created_at + interval '1 second'"); $this->clock->sleep(2); }
        if ($caso === 'inactivo') { $db->executeStatement('UPDATE usuario SET activo = false WHERE id = ?', [$data['usuarioId']]); }
        $password = $caso === 'corta' ? 'corta' : ($caso === 'larga' ? str_repeat('x', 73) : self::PASSWORD);
        $r = $this->request('POST', '/api/auth/configurar-password', ['token' => $caso === 'invalido' ? str_repeat('0', 64) : $token, 'password' => $password, 'passwordConfirmation' => $caso === 'confirmacion' ? 'distinta' : $password]);
        self::assertSame($status, $r->getStatusCode(), $r->getContent());
        self::assertNull($db->fetchOne('SELECT consumed_at FROM token_configuracion_password'));
        self::assertNull($db->fetchOne('SELECT password FROM usuario WHERE id = ?', [$data['usuarioId']]));
    }
    public static function passwordInvalido(): array { return [['expirado', 422], ['inactivo', 422], ['invalido', 422], ['confirmacion', 422], ['corta', 422], ['larga', 422]]; }

    private function configurar(string $token): \Symfony\Component\HttpFoundation\Response
    { return $this->request('POST', '/api/auth/configurar-password', ['token' => $token, 'password' => self::PASSWORD, 'passwordConfirmation' => self::PASSWORD]); }
}
