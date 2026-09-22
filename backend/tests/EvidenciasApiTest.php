<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\{Evidencia, RegistroAPPCC, Usuario, UsuarioEstablecimiento};
use App\Enum\RolEstablecimiento;
use App\Tests\Support\{EvidenciaFixtures, PostgresTestCase};
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\{Request, Response, StreamedResponse};

final class EvidenciasApiTest extends PostgresTestCase
{
    private string $jwt;
    private string $otroJwt;
    private int $programadaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario->setPassword('hash-solo-fixture');
        $this->otroUsuario->setPassword('hash-solo-fixture'); $this->em->flush();
        $jwt = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $this->jwt = $jwt->create($this->usuario); $this->otroJwt = $jwt->create($this->otroUsuario);
        $this->programadaId = $this->programar()->getId();
    }

    private function request(string $method, string $uri, ?array $datos = null, ?\Symfony\Component\HttpFoundation\File\UploadedFile $archivo = null, ?string $jwt = null, ?int $local = null): Response
    {
        self::getContainer()->get('security.token_storage')->setToken(null);
        $headers = ['CONTENT_TYPE' => $archivo ? 'multipart/form-data' : ($method === 'PATCH' ? 'application/merge-patch+json' : 'application/ld+json'), 'HTTP_ACCEPT' => 'application/ld+json'];
        if ($jwt !== '') { $headers['HTTP_AUTHORIZATION'] = 'Bearer '.($jwt ?? $this->jwt); }
        if ($local !== 0) { $headers['HTTP_X_ESTABLECIMIENTO_ID'] = (string) ($local ?? $this->local->getId()); }
        $request = Request::create($uri, $method, parameters: $archivo ? ($datos ?? []) : [], files: $archivo ? ['archivo' => $archivo] : [], server: $headers, content: $archivo || $datos === null ? null : json_encode($datos, JSON_THROW_ON_ERROR));
        $r = self::$kernel->handle($request); self::$kernel->terminate($request, $r);
        $this->em->clear();
        return $r;
    }

    private function json(Response $r, int $status): array
    {
        self::assertSame($status, $r->getStatusCode(), substr($r->getContent(), 0, 1200));
        return json_decode($r->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
    private function subir(string $extension = 'png'): array { return $this->json($this->request('POST', '/api/evidencias/subidas', ['tipo' => $extension === 'pdf' ? 'documento' : 'foto'], EvidenciaFixtures::archivo($extension)), 201); }
    private function enviarRegistro(array $extra = []): Response { return $this->request('POST', '/api/registros', $extra + ['tareaProgramada' => '/api/tareas-programadas/'.$this->programadaId, 'valorNumerico' => '3.500', 'observaciones' => 'Control realizado.']); }
    private function guardarFoto(): array
    {
        $token = $this->subir()['token'];
        $this->json($this->enviarRegistro(['evidencias' => [['token' => $token, 'tipo' => 'foto']], 'confirmarRegistro' => true]), 201);
        $e = $this->em->getRepository(Evidencia::class)->findOneBy([]);
        return ['id' => $e->getId(), 'storageKey' => $e->getStorageKey()];
    }

    #[DataProvider('formatos')]
    public function testSubidaHttpYRespuestaSinClavesInternas(string $extension): void
    {
        $r = $this->subir($extension);
        self::assertSame(['token', 'nombreOriginal', 'mimeType', 'tamanoBytes', 'expiresAt'], array_keys($r));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $r['token']);
    }
    public static function formatos(): array { return [['jpg'], ['png'], ['webp'], ['pdf']]; }

    public function testDescargaStreamingSerializacionYAuditoria(): void
    {
        $e = $this->guardarFoto();
        $datos = $this->json($this->request('GET', '/api/evidencias/'.$e['id']), 200);
        self::assertArrayNotHasKey('storageKey', $datos);
        self::assertStringNotContainsString($e['storageKey'], json_encode($datos));
        self::assertSame('/api/evidencias/'.$e['id'].'/descargar', $datos['downloadUrl'], json_encode($datos));
        $response = $this->request('GET', $datos['downloadUrl']);
        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString($e['storageKey'], (string) $response->headers);
        ob_start(); $response->sendContent(); $bytes = ob_get_clean();
        self::assertSame(file_get_contents(__DIR__.'/Fixtures/evidencia.png'), $bytes);
        $r = $this->em->getRepository(RegistroAPPCC::class)->findOneBy([]);
        self::assertSame($this->usuario->getId(), $r->getConfirmadoPor()->getId());
        self::assertSame($this->clock->now()->getTimestamp(), $r->getConfirmadoAt()->getTimestamp());
        self::assertSame($r->getUsuario()->getId(), $r->getConfirmadoPor()->getId());
    }

    #[DataProvider('erroresAcceso')]
    public function testSubidaYDescargaExigenJwtTenantYRol(string $caso, int $codigo): void
    {
        $e = $this->guardarFoto();
        $jwt = $caso === 'jwt' ? '' : $this->jwt;
        $local = $caso === 'cabecera' ? 0 : ($caso === 'membresia' ? $this->otroLocal->getId() : $this->local->getId());
        self::assertSame($codigo, $this->request('GET', '/api/evidencias/'.$e['id'].'/descargar', jwt: $jwt, local: $local)->getStatusCode());
        self::assertSame($codigo, $this->request('POST', '/api/evidencias/subidas', ['tipo' => 'foto'], EvidenciaFixtures::archivo(), $jwt, $local)->getStatusCode());
    }
    public static function erroresAcceso(): array { return [['jwt', 401], ['cabecera', 400], ['membresia', 403]]; }

    public function testAuditorDescargaPeroNoSubeNiRegistra(): void
    {
        $e = $this->guardarFoto();
        $this->em->getRepository(UsuarioEstablecimiento::class)->findOneBy(['usuario' => $this->usuario->getId(), 'establecimiento' => $this->local->getId()])->setRol(RolEstablecimiento::AUDITOR); $this->em->flush();
        $r = $this->request('GET', '/api/evidencias/'.$e['id'].'/descargar');
        self::assertSame(200, $r->getStatusCode()); ob_start(); $r->sendContent(); ob_end_clean();
        self::assertSame(403, $this->request('POST', '/api/evidencias/subidas', ['tipo' => 'foto'], EvidenciaFixtures::archivo())->getStatusCode());
        self::assertSame(403, $this->enviarRegistro()->getStatusCode());
    }

    public function testDescargaAjenaEs404(): void
    {
        $e = $this->guardarFoto();
        self::assertSame(404, $this->request('GET', '/api/evidencias/'.$e['id'].'/descargar', jwt: $this->otroJwt, local: $this->otroLocal->getId())->getStatusCode());
        self::assertSame(404, $this->request('GET', '/api/evidencias/999999/descargar')->getStatusCode());
    }

    #[DataProvider('auditoriaInyectada')]
    public function testNoAceptaCamposDeAuditoriaDelCliente(string $campo, mixed $valor): void
    {
        self::assertSame(400, $this->enviarRegistro([$campo => $valor])->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM registro_appcc'));
    }
    public static function auditoriaInyectada(): array
    {
        return [['usuario', '/api/usuarios/2'], ['establecimiento', '/api/establecimientos/2'], ['confirmadoPor', '/api/usuarios/2'],
            ['confirmadoAt', '2000-01-01T00:00:00Z'], ['createdAt', '2000-01-01T00:00:00Z'], ['versionDeclaracionFirma', 'otra'],
            ['storageKey', '../public/archivo'], ['mimeType', 'image/png'], ['tamanoBytes', 1], ['hashSha256', str_repeat('a', 64)], ['subidaPor', '/api/usuarios/2']];
    }

    public function testNoAceptaMetadatosDentroDeEvidencia(): void
    {
        $token = $this->subir()['token'];
        self::assertSame(422, $this->enviarRegistro(['evidencias' => [['token' => $token, 'tipo' => 'foto', 'storageKey' => '../archivo']]])->getStatusCode());
    }

    public function testFotoObligatoriaDevuelve422(): void
    {
        $r = $this->json($this->enviarRegistro(['valorNumerico' => '9']), 422);
        self::assertStringContainsString('fotografía', $r['detail']);
    }

    public function testFirmaRequeridaOmitidaDevuelve422(): void
    {
        $this->local->getConfiguracion()->setRequiereFirmaRegistro(true); $this->em->flush();
        self::assertSame(422, $this->enviarRegistro()->getStatusCode());
    }

    public function testNoHayPostJsonNiPatchNiDeleteHistoricos(): void
    {
        $e = $this->guardarFoto();
        self::assertSame(405, $this->request('POST', '/api/evidencias', ['storageKey' => 'inventada'])->getStatusCode());
        $id = $this->em->getRepository(RegistroAPPCC::class)->findOneBy([])->getId();
        foreach (['/api/evidencias/'.$e['id'], '/api/registros/'.$id] as $uri) {
            self::assertSame(405, $this->request('PATCH', $uri, ['confirmadoAt' => null])->getStatusCode());
            self::assertSame(405, $this->request('DELETE', $uri)->getStatusCode());
        }
    }

    public function testArchivoAusenteDevuelveErrorControlado(): void
    {
        $e = $this->guardarFoto();
        unlink($this->evidenciasDir.'/'.$e['storageKey']);
        $r = $this->request('GET', '/api/evidencias/'.$e['id'].'/descargar');
        self::assertSame(503, $r->getStatusCode());
        self::assertStringNotContainsString($e['storageKey'], $r->getContent());
        self::assertSame(1, $this->em->getRepository(Evidencia::class)->count([]));
    }
}
