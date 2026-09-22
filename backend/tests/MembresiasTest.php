<?php
declare(strict_types=1);
namespace App\Tests;

use App\Entity\{TareaProgramada, UsuarioEstablecimiento};
use App\Tests\Support\UsuariosApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MembresiasTest extends UsuariosApiTestCase
{
    public function testBajaDesasignaSoloAbiertasPreservaHistoricoYOtroEstablecimiento(): void
    {
        $this->segundoAdmin();
        $p = $this->programar('-1 minute');
        $v = $this->programar('-2 minutes'); $v->cambiarEstado(\App\Enum\EstadoTareaProgramada::VENCIDA);
        $registro = $this->registrarConFoto('9', '-3 minutes'); $c = $registro->getTareaProgramada();
        $o = $this->programar('-4 minutes'); $o->omitir('Cierre', $this->usuario, $this->clock->now());
        $otraMembresia = (new UsuarioEstablecimiento())->setUsuario($this->usuario)->setEstablecimiento($this->otroLocal)->setRol(\App\Enum\RolEstablecimiento::TRABAJADOR);
        $this->em->persist($otraMembresia); $this->em->flush();
        $db = $this->em->getConnection(); $memberId = $this->miembro();
        $historicos = $db->fetchAllAssociative("SELECT * FROM tarea_programada WHERE estado IN ('completada', 'omitida') ORDER BY id");
        $r = $this->request('POST', '/api/usuarios-establecimientos/'.$memberId.'/baja', jwt: $this->otroJwt, local: $this->local->getId());
        self::assertSame(200, $r->getStatusCode(), $r->getContent());
        self::assertFalse($db->fetchOne('SELECT activo FROM usuario_establecimiento WHERE id = ?', [$memberId]));
        self::assertTrue($db->fetchOne('SELECT activo FROM usuario WHERE id = ?', [$this->usuario->getId()]));
        self::assertTrue($db->fetchOne('SELECT activo FROM usuario_establecimiento WHERE id = ?', [$otraMembresia->getId()]));
        foreach ([$p, $v] as $abierta) { self::assertNull($db->fetchOne('SELECT asignado_a_id FROM tarea_programada WHERE id = ?', [$abierta->getId()])); }
        self::assertSame($historicos, $db->fetchAllAssociative("SELECT * FROM tarea_programada WHERE estado IN ('completada', 'omitida') ORDER BY id"));
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM registro_appcc'));
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM incidencia'));
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM historial_incidencia'));
        self::assertSame(4, (int) $db->fetchOne('SELECT count(*) FROM usuario_establecimiento'));
        self::assertSame(403, $this->api('GET', '/api/tareas-programadas')->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/tareas-programadas', jwt: $this->jwt, local: $this->otroLocal->getId())->getStatusCode());
        $r = $this->request('POST', '/api/usuarios-establecimientos/'.$memberId.'/reactivar', jwt: $this->otroJwt, local: $this->local->getId());
        self::assertSame(200, $r->getStatusCode(), $r->getContent());
        self::assertTrue($db->fetchOne('SELECT activo FROM usuario_establecimiento WHERE id = ?', [$memberId]));
        self::assertNull($db->fetchOne('SELECT asignado_a_id FROM tarea_programada WHERE id = ?', [$p->getId()]));
        self::assertSame($historicos, $db->fetchAllAssociative("SELECT * FROM tarea_programada WHERE estado IN ('completada', 'omitida') ORDER BY id"));
    }

    public function testFalloDesasignacionRevierteBaja(): void
    {
        $id = $this->segundoAdmin(); $p = $this->programar();
        $p->setAsignadoA($this->otroUsuario); $this->em->flush();
        $db = $this->em->getConnection();
        $db->executeStatement('ALTER TABLE tarea_programada ADD CONSTRAINT test_fallo_baja CHECK (asignado_a_id IS NOT NULL)');
        try {
            self::assertSame(500, $this->api('POST', '/api/usuarios-establecimientos/'.$id.'/baja')->getStatusCode());
            self::assertTrue($db->fetchOne('SELECT activo FROM usuario_establecimiento WHERE id = ?', [$id]));
            self::assertSame($this->otroUsuario->getId(), $db->fetchOne('SELECT asignado_a_id FROM tarea_programada WHERE id = ?', [$p->getId()]));
        } finally { $db->executeStatement('ALTER TABLE tarea_programada DROP CONSTRAINT test_fallo_baja'); }
    }

    public function testUltimoAdminBajaYCambioRol(): void
    {
        $id = $this->miembro();
        self::assertSame(422, $this->api('POST', '/api/usuarios-establecimientos/'.$id.'/baja')->getStatusCode());
        self::assertSame(422, $this->api('PATCH', '/api/usuarios-establecimientos/'.$id, ['rol' => 'trabajador'])->getStatusCode());
        self::assertTrue($this->em->getConnection()->fetchOne('SELECT activo FROM usuario_establecimiento WHERE id = ?', [$id]));
        self::assertSame('admin', $this->em->getConnection()->fetchOne('SELECT rol FROM usuario_establecimiento WHERE id = ?', [$id]));
    }

    public function testConDosAdminsPermiteCambioRol(): void
    {
        $id = $this->segundoAdmin();
        $r = $this->api('PATCH', '/api/usuarios-establecimientos/'.$id, ['rol' => 'responsable']);
        self::assertSame(200, $r->getStatusCode(), $r->getContent());
        self::assertSame('responsable', $this->em->getConnection()->fetchOne('SELECT rol FROM usuario_establecimiento WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT count(*) FROM usuario_establecimiento WHERE establecimiento_id = ? AND activo AND rol = 'admin'", [$this->local->getId()]));
    }

    #[DataProvider('operaciones')]
    public function testCrossTenant404YRol403(string $metodo, string $sufijo, ?array $input): void
    {
        $id = $this->miembro($this->otroUsuario->getId(), $this->otroLocal->getId());
        self::assertSame(404, $this->api($metodo, '/api/usuarios-establecimientos/'.$id.$sufijo, $input)->getStatusCode());
        $this->em->getConnection()->executeStatement("UPDATE usuario_establecimiento SET rol = 'trabajador' WHERE id = ?", [$this->miembro()]);
        self::assertSame(403, $this->api($metodo, '/api/usuarios-establecimientos/'.$this->miembro().$sufijo, $input)->getStatusCode());
    }
    public static function operaciones(): array { return [['POST', '/baja', null], ['POST', '/reactivar', null], ['PATCH', '', ['rol' => 'admin']]]; }

    public function testReactivacionRechazaActivaOGlobalInactivoYNoDuplica(): void
    {
        $id = $this->segundoAdmin(); $uri = '/api/usuarios-establecimientos/'.$id;
        self::assertSame(409, $this->api('POST', $uri.'/reactivar')->getStatusCode());
        self::assertSame(200, $this->api('POST', $uri.'/baja')->getStatusCode());
        self::assertSame(409, $this->api('POST', $uri.'/baja')->getStatusCode());
        $this->em->getConnection()->executeStatement('UPDATE usuario SET activo = false WHERE id = ?', [$this->otroUsuario->getId()]);
        self::assertSame(422, $this->api('POST', $uri.'/reactivar')->getStatusCode());
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM usuario_establecimiento'));
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT activo FROM usuario_establecimiento WHERE id = ?', [$id]));
    }

    #[DataProvider('camposMembresia')]
    public function testPatchNoPermiteCambiarIdentidadOrigenOActividad(string $campo, mixed $valor): void
    {
        $id = $this->segundoAdmin();
        self::assertSame(400, $this->api('PATCH', '/api/usuarios-establecimientos/'.$id, ['rol' => 'trabajador', $campo => $valor])->getStatusCode());
        self::assertTrue($this->em->getConnection()->fetchOne('SELECT activo FROM usuario_establecimiento WHERE id = ?', [$id]));
        self::assertSame('admin', $this->em->getConnection()->fetchOne('SELECT rol FROM usuario_establecimiento WHERE id = ?', [$id]));
    }
    public static function camposMembresia(): array { return [['activo', false], ['usuario', '/api/usuarios/1'], ['establecimiento', '/api/establecimientos/2']]; }

    public function testSinAltasGenericasNiDelete(): void
    {
        foreach ([['POST', '/api/usuarios'], ['POST', '/api/usuarios-establecimientos'], ['DELETE', '/api/usuarios/1'], ['DELETE', '/api/usuarios-establecimientos/'.$this->miembro()]] as [$m, $uri]) {
            self::assertContains($this->api($m, $uri, [])->getStatusCode(), [404, 405]);
        }
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM usuario'));
    }
}
