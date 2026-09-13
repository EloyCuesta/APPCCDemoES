<?php

declare(strict_types=1);

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
require __DIR__.'/../bootstrap.php';

use App\Tests\Support\PostgresSafety;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\{Clock, MockClock};
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

Clock::set(new MockClock('2026-09-12T10:00:00Z'));
$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get('doctrine')->getManager();
PostgresSafety::assertTestDatabase($em->getConnection());
$em->getConnection()->executeQuery("SELECT set_config('application_name', ?, false)", [$argv[1]]);
try {
    $app = new Application($kernel);
    $app->setAutoExit(false);
    $code = $app->run(new ArrayInput(['command' => 'app:tareas:procesar', '--desde' => '2026-09-11', '--hasta' => '2026-09-13', '--json' => true]), new ConsoleOutput());
} finally { $kernel->shutdown(); }
exit($code);
