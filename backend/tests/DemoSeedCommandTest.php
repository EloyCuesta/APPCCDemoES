<?php

declare(strict_types=1);

namespace App\Tests;

use App\Command\DemoSeedCommand;
use App\Entity\{AccionCorrectiva, AplicacionPlantillaAPPCC, ConfiguracionEntidadFiscal, ConfiguracionEstablecimiento, EntidadFiscal, Establecimiento, Evidencia, HistorialIncidencia, Incidencia, PlanControl, PlantillaAPPCC, PuntoControl, RegistroAPPCC, SubidaTemporalEvidencia, TareaAPPCC, TareaProgramada, Usuario, UsuarioEstablecimiento};
use App\Enum\{EstadoTareaProgramada, RolEstablecimiento, TipoActividad};
use App\Tests\Support\PostgresTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

final class DemoSeedCommandTest extends PostgresTestCase
{
    public function testPrimeraEjecucionIdempotenciaRolesYLoginReal(): void
    {
        $command = new CommandTester((new Application(self::$kernel))->find('app:demo:seed'));
        self::assertSame(0, $command->execute([]), $command->getDisplay());
        self::assertStringContainsString('creado', $command->getDisplay());
        $fiscal = $this->em->getRepository(EntidadFiscal::class)->findOneBy(['nif' => DemoSeedCommand::NIF]);
        $local = $this->em->getRepository(Establecimiento::class)->findOneBy(['entidadFiscal' => $fiscal, 'nombre' => DemoSeedCommand::NOMBRE]);
        self::assertSame(TipoActividad::RESTAURANTE, $local->getTipoActividad());
        self::assertTrue($local->getConfiguracion()->isGeneraIncidenciaAutomatica());
        self::assertTrue($local->getConfiguracion()->isRequiereFirmaRegistro());
        self::assertTrue($local->getConfiguracion()->isPermiteRegistrosAtrasados());
        self::assertSame(6, $this->em->getRepository(PlanControl::class)->count(['establecimiento' => $local]));
        self::assertSame(7, $this->em->getRepository(TareaAPPCC::class)->count(['establecimiento' => $local]));
        $obrador = $this->em->getRepository(Establecimiento::class)->findOneBy(['entidadFiscal' => $fiscal, 'nombre' => DemoSeedCommand::OBRADOR]);
        self::assertSame(TipoActividad::OBRADOR, $obrador->getTipoActividad());
        self::assertSame(0, $this->em->getRepository(TareaAPPCC::class)->count(['establecimiento' => $obrador]));
        $registros = $this->em->getRepository(RegistroAPPCC::class)->findBy(['establecimiento' => $local]);
        self::assertCount(3, $registros);
        self::assertCount(2, array_filter($registros, fn ($r) => $r->isConforme()));
        foreach ($registros as $registro) {
            self::assertSame('trabajador@appccdemo.local', $registro->getUsuario()->getEmail());
            self::assertSame($registro->getUsuario(), $registro->getConfirmadoPor());
            self::assertNotNull($registro->getConfirmadoAt());
            self::assertSame(EstadoTareaProgramada::COMPLETADA, $registro->getTareaProgramada()->getEstado());
        }
        $incidencia = $this->em->getRepository(Incidencia::class)->findOneBy(['establecimiento' => $local]);
        self::assertFalse($incidencia->getRegistro()->isConforme());
        self::assertSame(1, $this->em->getRepository(HistorialIncidencia::class)->count(['incidencia' => $incidencia]));
        $accion = $this->em->getRepository(AccionCorrectiva::class)->findOneBy(['incidencia' => $incidencia]);
        self::assertSame('responsable@appccdemo.local', $accion->getUsuario()->getEmail());
        $evidencia = $this->em->getRepository(Evidencia::class)->findOneBy(['registro' => $incidencia->getRegistro()]);
        self::assertTrue(self::getContainer()->get(\App\Service\Storage\EvidenciaStorageInterface::class)
            ->verificar($evidencia->getStorageKey(), $evidencia->getTamanoBytes(), $evidencia->getHashSha256()));
        $abiertas = $this->em->getRepository(TareaProgramada::class)->findBy(['establecimiento' => $local, 'estado' => EstadoTareaProgramada::PENDIENTE]);
        self::assertGreaterThan(7, count($abiertas));
        self::assertGreaterThanOrEqual(2, count(array_filter($abiertas, fn ($p) => $p->getFechaProgramada() <= $this->clock->now() && $p->getTarea()->getLimiteMinimo() !== null)));
        foreach ($local->getTareas() as $tarea) {
            self::assertTrue($tarea->isActiva());
            self::assertFalse($tarea->isConfiguracionPendiente());
        }
        $classes = [EntidadFiscal::class, Establecimiento::class, ConfiguracionEntidadFiscal::class, ConfiguracionEstablecimiento::class,
            Usuario::class, UsuarioEstablecimiento::class, PlantillaAPPCC::class, AplicacionPlantillaAPPCC::class,
            PuntoControl::class, PlanControl::class, TareaAPPCC::class, TareaProgramada::class,
            RegistroAPPCC::class, Incidencia::class, HistorialIncidencia::class, AccionCorrectiva::class, Evidencia::class, SubidaTemporalEvidencia::class];
        $ids = fn () => array_map(fn ($class) => array_map(fn ($e) => $e->getId(), $this->em->getRepository($class)->findBy([], ['id' => 'ASC'])), $classes);
        $before = $ids();
        self::assertSame(0, $command->execute([]), $command->getDisplay());
        self::assertStringContainsString('reutilizado', $command->getDisplay());
        self::assertSame($before, $ids(), 'La segunda ejecución no debe crear ninguna fila duplicada.');

        foreach (RolEstablecimiento::cases() as $rol) {
            $email = $rol->value.'@appccdemo.local';
            $usuario = $this->em->getRepository(Usuario::class)->findOneBy(['email' => $email]);
            self::assertTrue($usuario->isActivo());
            self::assertSame(['ROLE_USER'], $usuario->getRoles());
            $member = $this->em->getRepository(UsuarioEstablecimiento::class)->findOneBy(['usuario' => $usuario, 'establecimiento' => $local]);
            self::assertTrue($member->isActivo());
            self::assertSame($rol, $member->getRol());
            self::assertSame($rol, $this->em->getRepository(UsuarioEstablecimiento::class)
                ->findOneBy(['usuario' => $usuario, 'establecimiento' => $obrador])->getRol());
            self::getContainer()->get('security.token_storage')->setToken(null);
            $request = Request::create('/api/login_check', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email, 'password' => DemoSeedCommand::PASSWORD]));
            $response = self::$kernel->handle($request);
            self::$kernel->terminate($request, $response);
            self::assertSame(200, $response->getStatusCode());
            self::assertNotEmpty(json_decode($response->getContent(), true)['token']);
        }
    }

    public function testProduccionRechazadaAntesDeAccederALaBase(): void
    {
        $process = new Process([PHP_BINARY, 'bin/console', 'app:demo:seed', '--env=prod', '--no-debug', '--no-interaction'], dirname(__DIR__), [
            'APP_ENV' => 'prod', 'DATABASE_URL' => 'postgresql://invalid:invalid@127.0.0.1:1/unreachable?serverVersion=16',
        ]);
        $process->setTimeout(60);
        self::assertSame(1, $process->run());
        self::assertStringContainsString('prohibido en prod', $process->getOutput().$process->getErrorOutput());
        self::assertStringNotContainsString(DemoSeedCommand::PASSWORD, $process->getOutput());
    }
}
