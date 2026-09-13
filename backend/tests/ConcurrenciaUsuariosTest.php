<?php
declare(strict_types=1);
namespace App\Tests;

use App\Tests\Support\UsuariosApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

final class ConcurrenciaUsuariosTest extends UsuariosApiTestCase
{
    /** @var list<Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Compilar rutas antes de arrancar dos kernels: Windows no permite renombrar simultáneamente el mismo archivo de caché.
        self::getContainer()->get('router')->match('/api/invitaciones');
    }

    private function worker(array $input): Process
    {
        $nombre = 'appcc_usuarios_'.count($this->workers);
        $p = new Process([PHP_BINARY, __DIR__.'/Support/usuarios-worker.php', $nombre], dirname(__DIR__), ['APP_ENV' => 'test'], json_encode($input, JSON_THROW_ON_ERROR));
        $p->setTimeout(40); $p->start(); $this->workers[] = $p;
        return $p;
    }

    private function esperarBloqueos(int $numero = 2): void
    {
        $db = $this->em->getConnection(); $limite = microtime(true) + 20;
        do {
            $db->executeQuery('SELECT pg_stat_clear_snapshot()');
            if ((int) $db->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name LIKE 'appcc_usuarios_%' AND wait_event_type = 'Lock'") >= $numero) { self::assertTrue(true); return; }
            usleep(20000);
        } while (microtime(true) < $limite);
        self::fail('Los procesos no llegaron al bloqueo PostgreSQL: '.implode('\n', array_map(static fn ($p) => $p->getOutput().$p->getErrorOutput(), $this->workers)));
    }

    private function resultados(Process ...$workers): array
    {
        $resultados = [];
        foreach ($workers as $p) {
            self::assertSame(0, $p->wait(), $p->getErrorOutput());
            $resultados[] = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
        }
        sort($resultados); return $resultados;
    }

    private function bloquearLocal(): void
    {
        $db = $this->em->getConnection(); $db->beginTransaction();
        $db->fetchOne('SELECT id FROM establecimiento WHERE id = ? FOR NO KEY UPDATE', [$this->local->getId()]);
    }

    private function autenticada(string $uri, ?array $data = null, ?int $autor = null, string $method = 'POST'): array
    { return ['uri' => $uri, 'data' => $data, 'autor' => $autor ?? $this->usuario->getId(), 'local' => $this->local->getId(), 'method' => $method]; }

    #[DataProvider('duplicadosOnboarding')]
    public function testOnboardingConcurrenteUnSoloGrafo(string $duplicado): void
    {
        $db = $this->em->getConnection(); $db->beginTransaction(); $input = self::onboarding(); $otro = $input;
        if ($duplicado === 'email') {
            $otro['entidadFiscal']['nif'] = 'B66554433';
            $db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['identidad:maria@example.com']);
        } else {
            $otro['administrador']['email'] = 'otro@example.com';
            $db->executeStatement('LOCK TABLE entidad_fiscal IN SHARE MODE');
        }
        $a = $this->worker(['uri' => '/api/onboarding', 'data' => $input]);
        $b = $this->worker(['uri' => '/api/onboarding', 'data' => $otro]);
        $this->esperarBloqueos(); $db->commit();
        self::assertSame([201, 409], $this->resultados($a, $b));
        foreach (['entidad_fiscal', 'configuracion_entidad_fiscal', 'establecimiento', 'configuracion_establecimiento', 'usuario', 'usuario_establecimiento'] as $t) { self::assertSame(3, (int) $db->fetchOne('SELECT count(*) FROM '.$t)); }
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM token_configuracion_password'));
    }
    public static function duplicadosOnboarding(): array { return [['email'], ['nif']]; }

    public function testCreacionConcurrenteDeInvitacion(): void
    {
        $this->bloquearLocal();
        $input = $this->autenticada('/api/invitaciones', ['email' => 'nueva@example.com', 'rol' => 'trabajador']);
        $a = $this->worker($input); $b = $this->worker($input);
        $this->esperarBloqueos(); $this->em->getConnection()->commit();
        self::assertSame([201, 409], $this->resultados($a, $b));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM invitacion_usuario'));
    }

    public function testAceptacionConcurrenteConsumeUnaVez(): void
    {
        $i = $this->invitar(); $this->bloquearLocal();
        $input = ['uri' => '/api/invitaciones/aceptar', 'data' => ['token' => $i['token'], 'nombre' => 'Nueva', 'apellidos' => 'Persona']];
        $a = $this->worker($input); $b = $this->worker($input);
        $this->esperarBloqueos(); $this->em->getConnection()->commit();
        self::assertSame([200, 409], $this->resultados($a, $b));
        foreach (['usuario', 'usuario_establecimiento'] as $t) { self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM '.$t)); }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM token_configuracion_password'));
    }

    public function testDosInvitacionesDeEmpresasDistintasMismoEmailCreanUnaIdentidad(): void
    {
        $a = $this->invitar();
        $r = $this->request('POST', '/api/invitaciones', ['email' => 'NUEVA@example.com', 'rol' => 'auditor'], $this->otroJwt, $this->otroLocal->getId());
        self::assertSame(201, $r->getStatusCode()); $b = $this->json($r);
        $db = $this->em->getConnection(); $db->beginTransaction();
        $db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['identidad:nueva@example.com']);
        $workers = [];
        foreach ([$a, $b] as $i) { $workers[] = $this->worker(['uri' => '/api/invitaciones/aceptar', 'data' => ['token' => $i['token'], 'nombre' => 'Nueva', 'apellidos' => 'Persona']]); }
        $this->esperarBloqueos(); $db->commit();
        self::assertSame([200, 200], $this->resultados(...$workers));
        self::assertSame(3, (int) $db->fetchOne('SELECT count(*) FROM usuario'));
        self::assertSame(4, (int) $db->fetchOne('SELECT count(*) FROM usuario_establecimiento'));
        self::assertSame(2, (int) $db->fetchOne("SELECT count(*) FROM invitacion_usuario WHERE estado = 'aceptada'"));
    }

    public function testPasswordConcurrenteSoloUnConsumo(): void
    {
        $r = $this->request('POST', '/api/onboarding', self::onboarding()); self::assertSame(201, $r->getStatusCode());
        $token = $this->json($r)['tokenConfiguracionPassword'];
        $db = $this->em->getConnection(); $db->beginTransaction(); $db->fetchOne('SELECT id FROM token_configuracion_password FOR UPDATE');
        $data = ['token' => $token, 'password' => self::PASSWORD, 'passwordConfirmation' => self::PASSWORD];
        $a = $this->worker(['uri' => '/api/auth/configurar-password', 'data' => $data]);
        $data['password'] = $data['passwordConfirmation'] = 'Otra contraseña segura';
        $b = $this->worker(['uri' => '/api/auth/configurar-password', 'data' => $data]);
        $this->esperarBloqueos(); $db->commit();
        self::assertSame([204, 409], $this->resultados($a, $b));
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM token_configuracion_password WHERE consumed_at IS NOT NULL'));
        self::assertNotNull($db->fetchOne("SELECT password FROM usuario WHERE email = 'maria@example.com'"));
    }

    #[DataProvider('operacionesAdmin')]
    public function testDosAdminsNoPuedenDejarCero(string $caso): void
    {
        $segundo = $this->segundoAdmin(); $primero = $this->miembro();
        $this->bloquearLocal(); $workers = [];
        foreach ([[$primero, $this->usuario->getId()], [$segundo, $this->otroUsuario->getId()]] as [$id, $autor]) {
            if ($caso === 'mutua') { $id = $id === $primero ? $segundo : $primero; }
            $workers[] = $this->worker($this->autenticada('/api/usuarios-establecimientos/'.$id.($caso === 'rol' ? '' : '/baja'), $caso === 'rol' ? ['rol' => 'responsable'] : null, $autor, $caso === 'rol' ? 'PATCH' : 'POST'));
        }
        $this->esperarBloqueos(); $this->em->getConnection()->commit();
        self::assertSame($caso === 'mutua' ? [200, 403] : [200, 422], $this->resultados(...$workers));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT count(*) FROM usuario_establecimiento WHERE establecimiento_id = ? AND activo AND rol = 'admin'", [$this->local->getId()]));
    }
    public static function operacionesAdmin(): array { return [['propia'], ['mutua'], ['rol']]; }

    public function testAsignacionQueEsperaBajaRevalidaMembresia(): void
    {
        $id = $this->segundoAdmin(); $p = $this->programar(); $p->setAsignadoA($this->otroUsuario); $this->em->flush();
        $db = $this->em->getConnection(); $db->beginTransaction();
        $db->fetchOne('SELECT id FROM usuario_establecimiento WHERE id = ? FOR UPDATE', [$id]);
        $a = $this->worker($this->autenticada('/api/usuarios-establecimientos/'.$id.'/baja'));
        $this->esperarBloqueos(1);
        $b = $this->worker($this->autenticada('/api/tareas-programadas/'.$p->getId().'/asignar', ['usuario' => '/api/usuarios/'.$this->otroUsuario->getId()]));
        $this->esperarBloqueos(); $db->commit();
        self::assertSame([200, 422], $this->resultados($a, $b));
        self::assertFalse($db->fetchOne('SELECT activo FROM usuario_establecimiento WHERE id = ?', [$id]));
        self::assertNull($db->fetchOne('SELECT asignado_a_id FROM tarea_programada WHERE id = ?', [$p->getId()]));
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) { $this->em->getConnection()->rollBack(); }
        foreach ($this->workers as $p) { if ($p->isRunning()) { $p->stop(0); } }
        parent::tearDown();
    }
}
