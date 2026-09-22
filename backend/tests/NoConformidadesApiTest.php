<?php

declare(strict_types=1);

namespace App\Tests;

use App\Tests\Support\{EvidenciaFixtures, UsuariosApiTestCase};
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;

final class NoConformidadesApiTest extends UsuariosApiTestCase
{
    private function subir(string $extension): array
    {
        $request = Request::create('/api/evidencias/subidas', 'POST', parameters: ['tipo' => $extension === 'pdf' ? 'documento' : 'foto'],
            files: ['archivo' => EvidenciaFixtures::archivo($extension)], server: ['CONTENT_TYPE' => 'multipart/form-data',
                'HTTP_ACCEPT' => 'application/ld+json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->jwt, 'HTTP_X_ESTABLECIMIENTO_ID' => (string) $this->local->getId()]);
        $response = self::$kernel->handle($request); self::$kernel->terminate($request, $response);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        return ['token' => $this->json($response)['token'], 'tipo' => $extension === 'pdf' ? 'documento' : 'foto'];
    }

    #[DataProvider('controles')]
    public function testNoConformidadExigeFotoEnTodosLosTiposDeRespuesta(string $tipo, string $archivo): void
    {
        $input = ['observaciones' => 'Incumplimiento detectado.'];
        if ($tipo === 'numero') {
            // La conformidad manipulada debe ser recalculada por el servidor.
            $input += ['valorNumerico' => '9', 'conforme' => true];
        } else {
            $this->tarea->setLimiteMinimo(null)->setLimiteMaximo(null);
            if ($tipo === 'boolean') {
                $this->tarea->setConfiguracion(['tipoRespuesta' => 'boolean']);
                $input += ['datos' => ['resultado' => false], 'conforme' => true];
            } else {
                $this->tarea->setConfiguracion(['campos' => ['lote']]);
                $input += ['datos' => ['lote' => 'L-prueba'], 'conforme' => false];
            }
        }
        $this->em->flush(); $p = $this->programar();
        $input['tareaProgramada'] = '/api/tareas-programadas/'.$p->getId();
        if ($archivo !== 'ninguno') { $input['evidencias'] = [$this->subir($archivo)]; }
        $r = $this->api('POST', '/api/registros', $input);
        $db = $this->em->getConnection();
        if ($archivo === 'png') {
            self::assertSame(201, $r->getStatusCode(), $r->getContent()); self::assertFalse($this->json($r)['conforme']);
            foreach (['registro_appcc', 'incidencia', 'historial_incidencia', 'evidencia'] as $table) { self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM '.$table)); }
            self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM subida_temporal_evidencia WHERE consumida_at IS NOT NULL'));
            self::assertSame('completada', $db->fetchOne('SELECT estado FROM tarea_programada'));
            self::assertCount(1, glob($this->evidenciasDir.'/definitivo/*')); self::assertSame([], glob($this->evidenciasDir.'/temporal/*'));
        } else {
            self::assertSame(422, $r->getStatusCode(), $r->getContent()); self::assertStringContainsString('fotografía', $this->json($r)['detail']);
            foreach (['registro_appcc', 'incidencia', 'historial_incidencia', 'evidencia'] as $table) { self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM '.$table)); }
            self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM subida_temporal_evidencia WHERE consumida_at IS NOT NULL'));
            self::assertSame('pendiente', $db->fetchOne('SELECT estado FROM tarea_programada'));
        }
    }
    public static function controles(): iterable
    {
        foreach (['numero', 'boolean', 'estructurado'] as $tipo) { foreach (['ninguno', 'pdf', 'png'] as $archivo) { yield [$tipo, $archivo]; } }
    }

    public function testNoSePuedeDesactivarPorConfiguracion(): void
    {
        self::assertTrue($this->local->getConfiguracion()->isRequiereFotoNoConforme());
        $uri = '/api/configuraciones-establecimiento/'.$this->local->getConfiguracion()->getId();
        $r = $this->api('PATCH', $uri, ['requiereFotoNoConforme' => false]);
        self::assertSame(422, $r->getStatusCode(), $r->getContent());
        $r = $this->api('GET', $uri);
        self::assertSame(200, $r->getStatusCode(), $r->getContent()); self::assertTrue($this->json($r)['requiereFotoNoConforme']);
    }

    public function testSinFilaDeConfiguracionTambienExigeFoto(): void
    {
        $p = $this->programar();
        $this->em->getConnection()->delete('configuracion_establecimiento', ['id' => $this->local->getConfiguracion()->getId()]); $this->em->clear();
        $r = $this->api('POST', '/api/registros', ['tareaProgramada' => '/api/tareas-programadas/'.$p->getId(), 'valorNumerico' => '9', 'observaciones' => 'Incumplimiento.']);
        self::assertSame(422, $r->getStatusCode(), $r->getContent()); self::assertStringContainsString('fotografía', $this->json($r)['detail']);
    }

    public function testPeticionNoPuedeEnviarExencionDeFoto(): void
    {
        $p = $this->programar();
        $r = $this->api('POST', '/api/registros', ['tareaProgramada' => '/api/tareas-programadas/'.$p->getId(), 'valorNumerico' => '9', 'observaciones' => 'Incumplimiento.', 'requiereFotoNoConforme' => false]);
        self::assertSame(400, $r->getStatusCode(), $r->getContent());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM registro_appcc'));
    }
}
