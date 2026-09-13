<?php
declare(strict_types=1);

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
require __DIR__.'/../bootstrap.php';

use App\Entity\Usuario;
use App\Tests\Support\PostgresSafety;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Clock\{Clock, MockClock};
use Symfony\Component\HttpFoundation\Request;

Clock::set(new MockClock('2026-09-12T10:00:00Z'));
$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get('doctrine')->getManager();
PostgresSafety::assertTestDatabase($em->getConnection());
$em->getConnection()->executeQuery("SELECT set_config('application_name', ?, false)", [$argv[1]]);
try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $headers = ['CONTENT_TYPE' => ($input['method'] ?? 'POST') === 'PATCH' ? 'application/merge-patch+json' : 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];
    if (isset($input['autor'])) {
        $jwt = $container->get(JWTTokenManagerInterface::class)->create($em->find(Usuario::class, $input['autor']));
        $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$jwt;
        $headers['HTTP_X_ESTABLECIMIENTO_ID'] = (string) $input['local'];
    }
    $r = Request::create($input['uri'], $input['method'] ?? 'POST', server: $headers, content: json_encode($input['data'] ?? new \stdClass(), JSON_THROW_ON_ERROR));
    $response = $kernel->handle($r); $kernel->terminate($r, $response);
    // No emitir tokens ni cuerpos de respuesta; el padre verifica el estado final directamente en PostgreSQL.
    echo json_encode(['status' => $response->getStatusCode()], JSON_THROW_ON_ERROR);
} finally { $kernel->shutdown(); }
