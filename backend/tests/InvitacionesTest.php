<?php
declare(strict_types=1);
namespace App\Tests;

use App\Tests\Support\UsuariosApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class InvitacionesTest extends UsuariosApiTestCase
{
    public function testInvitacionNormalizadaTokenSoloInicialYAceptacion(): void
    {
        $i = $this->invitar(' NUEVA@EXAMPLE.COM ');
        self::assertSame('nueva@example.com', $i['email']);
        $db = $this->em->getConnection(); $fila = $db->fetchAssociative('SELECT * FROM invitacion_usuario WHERE id = ?', [$i['id']]);
        self::assertSame(hash('sha256', $i['token']), $fila['token_hash']);
        self::assertStringNotContainsString($i['token'], json_encode($fila));
        self::assertSame($this->local->getId(), $fila['establecimiento_id']);
        self::assertSame($this->usuario->getId(), $fila['invitada_por_id']);
        foreach (['/api/invitaciones', '/api/invitaciones/'.$i['id']] as $uri) {
            $r = $this->api('GET', $uri); self::assertSame(200, $r->getStatusCode(), $r->getContent());
            self::assertStringNotContainsString($i['token'], $r->getContent());
            self::assertStringNotContainsString($fila['token_hash'], $r->getContent());
            self::assertStringNotContainsString('tokenHash', $r->getContent());
        }
        $r = $this->aceptar($i['token']); self::assertSame(200, $r->getStatusCode(), $r->getContent());
        $alta = $this->json($r);
        self::assertNotEmpty($alta['tokenConfiguracionPassword']);
        self::assertSame(3, (int) $db->fetchOne('SELECT count(*) FROM usuario'));
        self::assertSame('trabajador', $db->fetchOne('SELECT rol FROM usuario_establecimiento WHERE id = ?', [$alta['membresiaId']]));
        self::assertSame('aceptada', $db->fetchOne('SELECT estado FROM invitacion_usuario WHERE id = ?', [$i['id']]));
        self::assertNotNull($db->fetchOne('SELECT accepted_at FROM invitacion_usuario WHERE id = ?', [$i['id']]));
        self::assertSame(409, $this->aceptar($i['token'])->getStatusCode());
        self::assertSame(409, $this->api('POST', '/api/invitaciones/'.$i['id'].'/cancelar')->getStatusCode());
    }

    #[DataProvider('rolesSinPermiso')]
    public function testGestionSoloAdmin(string $rol): void
    {
        $i = $this->invitar();
        $this->em->getConnection()->executeStatement('UPDATE usuario_establecimiento SET rol = ? WHERE id = ?', [$rol, $this->miembro()]);
        foreach ([['POST', '/api/invitaciones', ['email' => 'x@example.com', 'rol' => 'trabajador']], ['GET', '/api/invitaciones', null], ['POST', '/api/invitaciones/'.$i['id'].'/cancelar', null]] as [$metodo, $uri, $input]) {
            self::assertSame(403, $this->api($metodo, $uri, $input)->getStatusCode());
        }
    }
    public static function rolesSinPermiso(): array { return [['trabajador'], ['auditor'], ['responsable']]; }

    public function testSeguridadJwtCabeceraYMembresia(): void
    {
        $data = ['email' => 'x@example.com', 'rol' => 'trabajador'];
        self::assertSame(401, $this->request('POST', '/api/invitaciones', $data)->getStatusCode());
        self::assertSame(400, $this->request('POST', '/api/invitaciones', $data, $this->jwt)->getStatusCode());
        $this->em->getConnection()->executeStatement('UPDATE usuario_establecimiento SET activo = false WHERE id = ?', [$this->miembro()]);
        self::assertSame(403, $this->api('POST', '/api/invitaciones', $data)->getStatusCode());
        self::assertSame(401, $this->request('GET', '/api/usuarios')->getStatusCode());
    }

    public function testDuplicadosYExpiracionPermiteReinvitar(): void
    {
        self::assertSame(409, $this->api('POST', '/api/invitaciones', ['email' => ' ANA@EXAMPLE.COM ', 'rol' => 'trabajador'])->getStatusCode());
        $i = $this->invitar();
        self::assertSame(409, $this->api('POST', '/api/invitaciones', ['email' => 'NUEVA@example.com', 'rol' => 'auditor'])->getStatusCode());
        $this->clock->sleep(8 * 86400);
        $nueva = $this->invitar();
        self::assertNotSame($i['token'], $nueva['token']);
        self::assertSame('expirada', $this->em->getConnection()->fetchOne('SELECT estado FROM invitacion_usuario WHERE id = ?', [$i['id']]));
        self::assertSame(422, $this->aceptar($i['token'])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/invitaciones/'.$i['id'].'/cancelar')->getStatusCode());
    }

    public function testAislamientoAntesDePaginacionYCancelacion(): void
    {
        $propia = $this->invitar();
        $r = $this->request('POST', '/api/invitaciones', ['email' => 'ajena@example.com', 'rol' => 'admin'], $this->otroJwt, $this->otroLocal->getId());
        self::assertSame(201, $r->getStatusCode(), $r->getContent()); $ajena = $this->json($r);
        $r = $this->api('GET', '/api/invitaciones');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame(1, $this->json($r)['totalItems']);
        self::assertSame([$propia['id']], array_column($this->json($r)['member'], 'id'));
        self::assertSame(404, $this->api('GET', '/api/invitaciones/'.$ajena['id'])->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/invitaciones/'.$ajena['id'].'/cancelar')->getStatusCode());
        self::assertSame('pendiente', $this->em->getConnection()->fetchOne('SELECT estado FROM invitacion_usuario WHERE id = ?', [$ajena['id']]));
    }

    public function testCancelarPendienteImpideAceptacionYRepeticion(): void
    {
        $i = $this->invitar();
        self::assertSame(200, $this->api('POST', '/api/invitaciones/'.$i['id'].'/cancelar')->getStatusCode());
        $fila = $this->em->getConnection()->fetchAssociative('SELECT estado, cancelled_at FROM invitacion_usuario WHERE id = ?', [$i['id']]);
        self::assertSame('cancelada', $fila['estado']); self::assertNotNull($fila['cancelled_at']);
        self::assertSame(409, $this->aceptar($i['token'])->getStatusCode());
        self::assertSame(409, $this->api('POST', '/api/invitaciones/'.$i['id'].'/cancelar')->getStatusCode());
        self::assertNotSame($i['id'], $this->invitar()['id']);
    }

    #[DataProvider('invitacionesInvalidas')]
    public function testAceptacionInvalidaNoCreaUsuario(string $caso): void
    {
        $i = $this->invitar();
        if ($caso === 'expirada') { $this->clock->sleep(8 * 86400); }
        $r = $this->aceptar($caso === 'inexistente' ? str_repeat('f', 64) : $i['token'], $caso === 'sinNombre' ? '' : 'Nueva');
        self::assertSame(422, $r->getStatusCode(), $r->getContent());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM usuario'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM token_configuracion_password'));
    }
    public static function invitacionesInvalidas(): array { return [['expirada'], ['inexistente'], ['sinNombre']]; }

    #[DataProvider('usuariosExistentes')]
    public function testReutilizaIdentidadYMembresiaSinCambiarPassword(string $caso): void
    {
        $db = $this->em->getConnection(); $u = $this->otroUsuario->getId();
        $hash = $db->fetchOne('SELECT password FROM usuario WHERE id = ?', [$u]);
        $memberId = null;
        if ($caso === 'membresiaInactiva') { $memberId = $this->segundoAdmin(); $db->executeStatement('UPDATE usuario_establecimiento SET activo = false WHERE id = ?', [$memberId]); }
        if ($caso === 'globalInactivo') { $db->executeStatement('UPDATE usuario SET activo = false WHERE id = ?', [$u]); }
        $i = $this->invitar(' LUIS@EXAMPLE.COM ', 'auditor');
        $r = $this->aceptar($i['token']);
        self::assertSame($caso === 'globalInactivo' ? 422 : 200, $r->getStatusCode(), $r->getContent());
        self::assertSame(2, (int) $db->fetchOne('SELECT count(*) FROM usuario'));
        self::assertSame($hash, $db->fetchOne('SELECT password FROM usuario WHERE id = ?', [$u]));
        self::assertSame('Luis', $db->fetchOne('SELECT nombre FROM usuario WHERE id = ?', [$u]));
        self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM token_configuracion_password'));
        if ($caso === 'globalInactivo') { self::assertFalse($db->fetchOne('SELECT activo FROM usuario WHERE id = ?', [$u])); return; }
        $data = $this->json($r); self::assertSame($u, $data['usuarioId']);
        self::assertNull($data['tokenConfiguracionPassword'] ?? null);
        if ($memberId !== null) { self::assertSame($memberId, $data['membresiaId']); }
        self::assertSame('auditor', $db->fetchOne('SELECT rol FROM usuario_establecimiento WHERE id = ?', [$data['membresiaId']]));
        self::assertTrue($db->fetchOne('SELECT activo FROM usuario_establecimiento WHERE id = ?', [$data['membresiaId']]));
        self::assertSame(3, (int) $db->fetchOne('SELECT count(*) FROM usuario_establecimiento'));
    }
    public static function usuariosExistentes(): array { return [['existente'], ['membresiaInactiva'], ['globalInactivo']]; }

    public function testAltaNoPuedeCambiarRolNiOrigenPorJson(): void
    {
        self::assertSame(400, $this->api('POST', '/api/invitaciones', ['email' => 'x@example.com', 'rol' => 'trabajador', 'establecimiento' => '/api/establecimientos/2'])->getStatusCode());
        $i = $this->invitar();
        self::assertSame(400, $this->request('POST', '/api/invitaciones/aceptar', ['token' => $i['token'], 'nombre' => 'X', 'apellidos' => 'Y', 'rol' => 'admin'])->getStatusCode());
        self::assertContains($this->api('PATCH', '/api/invitaciones/'.$i['id'], ['estado' => 'aceptada'])->getStatusCode(), [404, 405]);
    }

    private function aceptar(string $token, string $nombre = 'Nueva'): \Symfony\Component\HttpFoundation\Response
    { return $this->request('POST', '/api/invitaciones/aceptar', ['token' => $token, 'nombre' => $nombre, 'apellidos' => 'Persona']); }
}
