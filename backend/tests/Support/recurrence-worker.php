<?php

declare(strict_types=1);

// Proceso independiente: comparte únicamente la base PostgreSQL de pruebas autorizada.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
require dirname(__DIR__).'/bootstrap.php';

use App\Entity\TareaAPPCC;
use App\Service\{GeneradorTareasProgramadasService, TareaAPPCCService};
use App\Tests\Support\PostgresSafety;
use Symfony\Component\Clock\{Clock, MockClock};

Clock::set(new MockClock('2026-09-12T10:00:00Z'));
$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get('doctrine')->getManager();
PostgresSafety::assertTestDatabase($em->getConnection());
$em->getConnection()->executeQuery("SELECT set_config('application_name', ?, false)", [$argv[3]]);
$tarea = $em->find(TareaAPPCC::class, (int) $argv[2]);
echo "READY\n";
flush();
try {
    if ($argv[1] === 'generar') {
        $r = $container->get(GeneradorTareasProgramadasService::class)->generar(new DateTimeImmutable('2026-09-13'), new DateTimeImmutable('2026-09-15'), true);
        echo json_encode(['creadas' => $r->creadas, 'existentes' => $r->existentes], JSON_THROW_ON_ERROR)."\n";
    } else {
        $tarea->setHoraPrevista(new DateTimeImmutable('10:00:00'));
        $container->get(TareaAPPCCService::class)->guardarCambios($tarea);
        echo "UPDATED\n";
    }
} finally { $kernel->shutdown(); }
