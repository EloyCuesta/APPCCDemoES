<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\{Usuario, UsuarioEstablecimiento};
use App\Enum\{RolEstablecimiento, TipoEntidadFiscal};
use App\Tests\Support\UsuariosApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class MeApiTest extends UsuariosApiTestCase
{
    public function testSinJwtEs401(): void
    {
        self::assertSame(401, $this->request('GET', '/api/me')->getStatusCode());
    }

    public function testJwtInvalidoEs401(): void
    {
        self::assertSame(401, $this->request('GET', '/api/me', jwt: 'token-no-valido')->getStatusCode());
    }

    public function testUsuarioInactivoNoPuedeUsarJwtPrevio(): void
    {
        $this->usuario->setActivo(false); $this->em->flush();
        self::assertSame(401, $this->request('GET', '/api/me', jwt: $this->jwt)->getStatusCode());
    }

    #[DataProvider('roles')]
    public function testContratoSinCabeceraTenantParaTodosLosRoles(RolEstablecimiento $rol): void
    {
        $m = $this->em->find(UsuarioEstablecimiento::class, $this->miembro());
        $m->setRol($rol);
        $this->usuario->setRoles(['ROLE_PLATFORM_ADMIN']); // Tampoco se exponen roles globales.
        $this->em->flush();
        $response = $this->request('GET', '/api/me', jwt: $this->jwt);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertContains('Authorization', $response->getVary());
        // Igualdad completa: sin password, hashes, tokens, roles globales o relaciones adicionales.
        self::assertSame([
            'id' => $this->usuario->getId(), 'nombre' => 'Ana', 'apellidos' => 'García', 'email' => 'ana@example.com',
            'membresias' => [[
                'id' => $m->getId(), 'iri' => '/api/usuarios-establecimientos/'.$m->getId(), 'rol' => $rol->value,
                'establecimiento' => [
                    'id' => $this->local->getId(), 'iri' => '/api/establecimientos/'.$this->local->getId(),
                    'nombre' => 'Obrador', 'tipoActividad' => 'obrador',
                    'entidadFiscal' => ['id' => $this->local->getEntidadFiscal()->getId(), 'nombre' => 'Empresa prueba'],
                ],
            ]],
            'establecimientoPredeterminadoId' => $this->local->getId(),
        ], $this->json($response));
        self::assertStringNotContainsString($this->usuario->getPassword(), $response->getContent());
        self::assertStringNotContainsString($this->jwt, $response->getContent());
    }
    public static function roles(): array { return array_map(static fn ($rol) => [$rol], RolEstablecimiento::cases()); }

    private function segundaMembresia(): UsuarioEstablecimiento
    {
        $m = (new UsuarioEstablecimiento())->setUsuario($this->usuario)->setEstablecimiento($this->otroLocal)->setRol(RolEstablecimiento::TRABAJADOR);
        $this->em->persist($m); $this->em->flush();
        return $m;
    }

    public function testVariasMembresiasConRolesYEntidadesFiscalesPropios(): void
    {
        $primera = $this->miembro();
        $segunda = $this->segundaMembresia()->getId();
        $this->otroLocal->getEntidadFiscal()->setNombreComercial('Catering del Sur'); $this->em->flush();
        $response = $this->request('GET', '/api/me', jwt: $this->jwt);
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame([$primera, $segunda], array_column($data['membresias'], 'id'));
        self::assertSame(['admin', 'trabajador'], array_column($data['membresias'], 'rol'));
        self::assertSame([$this->local->getId(), $this->otroLocal->getId()], array_column(array_column($data['membresias'], 'establecimiento'), 'id'));
        self::assertSame(['id' => $this->otroLocal->getEntidadFiscal()->getId(), 'nombre' => 'Catering del Sur'], $data['membresias'][1]['establecimiento']['entidadFiscal']);
        self::assertNull($data['establecimientoPredeterminadoId']);

        // La cabecera, aunque sea ajena, no selecciona ni limita el contexto de descubrimiento.
        self::assertSame($data, $this->json($this->request('GET', '/api/me', jwt: $this->jwt, local: 999999)));
    }

    #[DataProvider('ambitosInactivos')]
    public function testFiltraTodoAmbitoInactivoAntesDeElegirPredeterminado(string $ambito): void
    {
        $m = $this->segundaMembresia();
        $objeto = match ($ambito) { 'membresia' => $m, 'establecimiento' => $this->otroLocal, 'fiscal' => $this->otroLocal->getEntidadFiscal() };
        $objeto->setActivo(false); $this->em->flush();
        $response = $this->request('GET', '/api/me', jwt: $this->jwt);
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertCount(1, $data['membresias']);
        self::assertSame($this->local->getId(), $data['membresias'][0]['establecimiento']['id']);
        self::assertSame($this->local->getId(), $data['establecimientoPredeterminadoId']);
    }
    public static function ambitosInactivos(): array { return [['membresia'], ['establecimiento'], ['fiscal']]; }

    #[DataProvider('ambitosInactivos')]
    public function testSinEstablecimientosValidosDevuelve200YListaVacia(string $ambito): void
    {
        $objeto = match ($ambito) {
            'membresia' => $this->em->find(UsuarioEstablecimiento::class, $this->miembro()),
            'establecimiento' => $this->local, 'fiscal' => $this->local->getEntidadFiscal(),
        };
        $objeto->setActivo(false); $this->em->flush();
        $response = $this->request('GET', '/api/me', jwt: $this->jwt);
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame($this->usuario->getId(), $data['id']);
        self::assertSame([], $data['membresias']);
        self::assertNull($data['establecimientoPredeterminadoId']);
    }

    public function testUsuarioQueNuncaTuvoMembresias(): void
    {
        $nuevo = (new Usuario())->setNombre('Eva')->setApellidos('López')->setEmail('eva@example.com')->setPassword($this->usuario->getPassword());
        $this->em->persist($nuevo); $this->em->flush();
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($nuevo);
        $response = $this->request('GET', '/api/me', jwt: $jwt);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => $nuevo->getId(), 'nombre' => 'Eva', 'apellidos' => 'López', 'email' => 'eva@example.com', 'membresias' => [], 'establecimientoPredeterminadoId' => null], $this->json($response));
    }

    public function testSoloMuestraMembresiasDelJwtSinSuplantacionPorParametros(): void
    {
        $response = $this->request('GET', '/api/me?usuario='.$this->otroUsuario->getId().'&establecimiento='.$this->otroLocal->getId(), jwt: $this->jwt);
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame($this->usuario->getId(), $data['id']);
        self::assertCount(1, $data['membresias']);
        self::assertSame($this->local->getId(), $data['membresias'][0]['establecimiento']['id']);
        $otro = $this->json($this->request('GET', '/api/me', jwt: $this->otroJwt));
        self::assertSame($this->otroUsuario->getId(), $otro['id']);
        self::assertSame($this->otroLocal->getId(), $otro['membresias'][0]['establecimiento']['id']);
    }

    public function testReconsultaUnaMembresiaRevocadaTrasElLogin(): void
    {
        $id = $this->miembro();
        self::assertCount(1, $this->json($this->request('GET', '/api/me', jwt: $this->jwt))['membresias']);
        $this->em->find(UsuarioEstablecimiento::class, $id)->setActivo(false); $this->em->flush();
        $response = $this->request('GET', '/api/me', jwt: $this->jwt);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->json($response)['membresias']);
    }

    #[DataProvider('nombresFiscales')]
    public function testNombreFiscalDePresentacion(?string $comercial, ?string $razon, bool $autonomo, string $esperado): void
    {
        $this->local->getEntidadFiscal()->setNombreComercial($comercial)->setRazonSocial($razon)
            ->setTipo($autonomo ? TipoEntidadFiscal::AUTONOMO : TipoEntidadFiscal::EMPRESA)->setNombre('Elena')->setApellidos('Ruiz');
        $this->em->flush();
        $response = $this->request('GET', '/api/me', jwt: $this->jwt);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($esperado, $this->json($response)['membresias'][0]['establecimiento']['entidadFiscal']['nombre']);
    }
    public static function nombresFiscales(): array
    {
        return [['  Comercio  ', 'Empresa SL', false, 'Comercio'], ['0', 'Empresa SL', false, '0'], ['   ', 'Empresa SL', false, 'Empresa SL'], [null, null, true, 'Elena Ruiz']];
    }

    public function testLoginMantieneContratoYSigueExigiendoseTenantEnLosDemasEndpoints(): void
    {
        $login = $this->request('POST', '/api/login_check', ['email' => $this->usuario->getEmail(), 'password' => self::PASSWORD]);
        self::assertSame(200, $login->getStatusCode());
        self::assertSame(['token'], array_keys($this->json($login)));
        $jwt = $this->json($login)['token'];
        self::assertIsString($jwt);
        self::assertSame(200, $this->request('GET', '/api/me', jwt: $jwt)->getStatusCode());
        foreach (['/api/establecimientos', '/api/usuarios-establecimientos', '/api/tareas-programadas'] as $uri) {
            self::assertSame(400, $this->request('GET', $uri, jwt: $jwt)->getStatusCode(), $uri);
        }
        self::assertSame(403, $this->request('GET', '/api/establecimientos', jwt: $jwt, local: $this->otroLocal->getId())->getStatusCode());
        self::assertSame(404, $this->request('GET', '/api/establecimientos/'.$this->otroLocal->getId(), jwt: $jwt, local: $this->local->getId())->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/establecimientos/'.$this->local->getId(), jwt: $jwt, local: $this->local->getId())->getStatusCode());
    }

    public function testNoExponeEscriturasDeContexto(): void
    {
        foreach (['POST', 'PATCH', 'DELETE'] as $method) {
            self::assertSame(405, $this->request($method, '/api/me', [], $this->jwt)->getStatusCode());
        }
    }
}
