<?php
declare(strict_types=1);
namespace App\Tests\Support;

use App\Entity\{Usuario, UsuarioEstablecimiento};
use App\Enum\RolEstablecimiento;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class UsuariosApiTestCase extends PostgresTestCase
{
    protected string $jwt;
    protected string $otroJwt;
    protected const PASSWORD = 'Clave inicial segura 2026';

    protected function setUp(): void
    {
        parent::setUp();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([$this->usuario, $this->otroUsuario] as $u) { $u->setPassword($hasher->hashPassword($u, self::PASSWORD)); }
        $this->em->flush();
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class);
        $this->jwt = $jwt->create($this->usuario);
        $this->otroJwt = $jwt->create($this->otroUsuario);
    }

    protected function request(string $method, string $uri, ?array $data = null, ?string $jwt = null, ?int $local = null): Response
    {
        self::getContainer()->get('security.token_storage')->setToken(null);
        $headers = ['CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];
        if ($jwt !== null) { $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$jwt; }
        if ($local !== null) { $headers['HTTP_X_ESTABLECIMIENTO_ID'] = (string) $local; }
        $request = Request::create($uri, $method, server: $headers, content: $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR));
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        if (!$this->em->isOpen()) { self::getContainer()->get('doctrine')->resetManager(); $this->em = self::getContainer()->get('doctrine')->getManager(); }
        $this->em->clear();
        return $response;
    }

    protected function api(string $method, string $uri, ?array $data = null): Response
    { return $this->request($method, $uri, $data, $this->jwt, $this->local->getId()); }

    protected function json(Response $r): array { return json_decode($r->getContent(), true, flags: JSON_THROW_ON_ERROR); }

    protected function invitar(string $email = 'nueva@example.com', string $rol = 'trabajador'): array
    {
        $r = $this->api('POST', '/api/invitaciones', ['email' => $email, 'rol' => $rol]);
        self::assertSame(201, $r->getStatusCode(), $r->getContent());
        self::assertTrue($r->headers->hasCacheControlDirective('no-store'));
        return $this->json($r);
    }

    protected function miembro(?int $usuario = null, ?int $local = null): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT id FROM usuario_establecimiento WHERE usuario_id = ? AND establecimiento_id = ?', [$usuario ?? $this->usuario->getId(), $local ?? $this->local->getId()]);
    }

    protected function segundoAdmin(): int
    {
        $m = (new UsuarioEstablecimiento())->setUsuario($this->em->find(Usuario::class, $this->otroUsuario->getId()))
            ->setEstablecimiento($this->em->find(\App\Entity\Establecimiento::class, $this->local->getId()))->setRol(RolEstablecimiento::ADMIN);
        $this->em->persist($m); $this->em->flush();
        return $m->getId();
    }

    public static function onboarding(): array
    {
        $direccion = ['direccion' => 'Mayor 1', 'codigoPostal' => '28001', 'localidad' => 'Madrid', 'provincia' => 'Madrid'];
        return ['entidadFiscal' => ['tipo' => 'empresa', 'nif' => 'B99887766', 'razonSocial' => 'Nueva SL'] + $direccion,
            'establecimiento' => ['nombre' => 'Nuevo local', 'tipoActividad' => 'restaurante'] + $direccion,
            'administrador' => ['nombre' => 'María', 'apellidos' => 'López', 'email' => 'MARIA@example.com']];
    }
}
