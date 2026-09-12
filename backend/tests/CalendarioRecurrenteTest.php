<?php

declare(strict_types=1);

namespace App\Tests;

use App\Command\GenerarTareasCommand;
use App\Entity\{TareaAPPCC, TareaProgramada, PuntoControl, PlantillaAPPCC};
use App\Enum\{FrecuenciaTarea, TipoPuntoControl, TipoActividad};
use App\Service\{GeneradorTareasProgramadasService, PlantillaAPPCCService};
use App\Service\Support\CalendarioAPPCC;
use App\Tests\Support\PostgresTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;

final class CalendarioRecurrenteTest extends PostgresTestCase
{
    public static function fechasInvalidas(): iterable
    {
        foreach (['2026-02-30', '2026-13-01', '0000-01-01', '2026-02-30T09:00:00Z', '2026-09-12T25:00:00Z', '2026-09-12T09:00:00+14:30', '2026-09-12T09:00:00', 'tomorrow', '2026-9-1', ''] as $valor) { yield [$valor]; }
    }

    #[DataProvider('fechasInvalidas')]
    public function testComandoRechazaFechasInvalidas(string $valor): void
    {
        $command = new CommandTester(self::getContainer()->get(GenerarTareasCommand::class));
        self::assertSame(2, $command->execute(['--desde' => $valor]));
        self::assertSame(0, $this->em->getRepository(TareaProgramada::class)->count([]));
    }

    public static function limites(): iterable
    {
        foreach (['Pacific/Kiritimati', 'America/Los_Angeles'] as $zona) {
            yield [$zona, ['--desde' => '2026-10-01'], '2026-10-01', '2026-10-08'];
            yield [$zona, ['--hasta' => '2026-10-08'], '2026-10-01', '2026-10-08'];
            yield [$zona, ['--desde' => '2026-10-01', '--hasta' => '2026-10-01'], '2026-10-01', '2026-10-01'];
        }
    }

    #[DataProvider('limites')]
    public function testLimitesCalendarioSonLocales(string $zona, array $opciones, string $inicio, string $fin): void
    {
        $this->local->getEntidadFiscal()->getConfiguracion()->setZonaHoraria($zona);
        $this->tarea->setHoraPrevista(new \DateTimeImmutable('00:30:00')); $this->em->flush();
        $command = new CommandTester(self::getContainer()->get(GenerarTareasCommand::class));
        self::assertSame(0, $command->execute($opciones), $command->getDisplay());
        $filas = $this->em->getRepository(TareaProgramada::class)->findBy([], ['fechaProgramada' => 'ASC']);
        self::assertCount($inicio === $fin ? 1 : 8, $filas);
        self::assertSame($inicio.' 00:30', $filas[0]->getFechaProgramada()->setTimezone(new \DateTimeZone($zona))->format('Y-m-d H:i'));
        self::assertSame($fin.' 00:30', $filas[array_key_last($filas)]->getFechaProgramada()->setTimezone(new \DateTimeZone($zona))->format('Y-m-d H:i'));
    }

    public function testComandoIsoDefaultsOrdenYFormatosMixtos(): void
    {
        $this->tarea->setCreatedAt(new \DateTimeImmutable('2026-01-01')); $this->em->flush();
        $command = new CommandTester(self::getContainer()->get(GenerarTareasCommand::class));
        self::assertSame(2, $command->execute(['--desde' => '2026-10-01', '--hasta' => '2026-10-02T00:00:00Z']));
        self::assertSame(2, $command->execute(['--desde' => '2026-10-02', '--hasta' => '2026-10-01']));
        self::assertSame(0, $command->execute(['--hasta' => '2026-10-08T00:00:00Z']));
        self::assertSame(7, $this->em->getRepository(TareaProgramada::class)->count([]));
        self::assertSame(0, $command->execute(['--desde' => '2026-10-01T00:00:00+00:00']));
        self::assertSame(7, $this->em->getRepository(TareaProgramada::class)->count([]));
        self::assertSame(0, $command->execute([]));
        self::assertSame(14, $this->em->getRepository(TareaProgramada::class)->count([]));
    }

    public static function cambiosHorario(): iterable
    {
        yield ['2026-03-29', '01:30:00', '01:30 +01:00'];
        yield ['2026-10-25', '01:30:00', '01:30 +02:00'];
        yield ['2026-03-29', '02:30:00', '03:30 +02:00'];
        yield ['2026-10-25', '02:30:00', '02:30 +02:00'];
    }

    #[DataProvider('cambiosHorario')]
    public function testHorarioEstacionalYPlazoReal(string $dia, string $hora, string $esperada): void
    {
        $this->tarea->setCreatedAt(new \DateTimeImmutable('2026-01-01'))->setHoraPrevista(new \DateTimeImmutable($hora))->setPlazoMinutos(120); $this->em->flush();
        $service = self::getContainer()->get(GeneradorTareasProgramadasService::class);
        $r = $service->generar(new \DateTimeImmutable($dia), new \DateTimeImmutable($dia), true);
        self::assertSame(1, $r->creadas);
        $p = $this->em->getRepository(TareaProgramada::class)->findOneBy([]);
        self::assertSame($esperada, $p->getFechaProgramada()->setTimezone(new \DateTimeZone('Europe/Madrid'))->format('H:i P'));
        self::assertSame(7200, $p->getFechaLimite()->getTimestamp() - $p->getFechaProgramada()->getTimestamp());
    }

    public function testMesBisiestoYLimiteCreatedAtConMicrosegundos(): void
    {
        $this->tarea->setFrecuencia(FrecuenciaTarea::MENSUAL)->setDiaMes(31)->setCreatedAt(new \DateTimeImmutable('2028-01-01')); $this->em->flush();
        $calendario = self::getContainer()->get(CalendarioAPPCC::class);
        $fechas = $calendario->ocurrencias($this->tarea, $this->local, new \DateTimeImmutable('2028-02-01'), new \DateTimeImmutable('2028-05-01'));
        self::assertSame(['2028-02-29', '2028-03-31', '2028-04-30'], array_map(static fn ($d) => $d->format('Y-m-d'), $fechas));
        $this->tarea->setFrecuencia(FrecuenciaTarea::DIARIA)->setDiaMes(null)->setCreatedAt(new \DateTimeImmutable('2026-09-13T07:00:00.000001Z'));
        self::assertCount(0, $calendario->ocurrencias($this->tarea, $this->local, new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-14')));
        $this->tarea->setCreatedAt(new \DateTimeImmutable('2026-09-13T07:00:00Z'));
        self::assertCount(1, $calendario->ocurrencias($this->tarea, $this->local, new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-14')));
    }

    public function testPuntoInactivoYContextoIncoherenteNoGeneran(): void
    {
        $punto = (new PuntoControl())->setNombre('Punto')->setTipo(TipoPuntoControl::cases()[0])->setEstablecimiento($this->local)->setActivo(false);
        $this->em->persist($punto); $this->tarea->setPuntoControl($punto); $this->em->flush();
        $service = self::getContainer()->get(GeneradorTareasProgramadasService::class);
        self::assertSame(0, $service->generar(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-02'))->creadas);
        $punto->setActivo(true)->setEstablecimiento($this->otroLocal); $this->em->flush();
        self::assertSame(0, $service->generar(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-02'))->creadas);
    }

    public static function configuracionesInvalidas(): iterable
    {
        yield ['setDiaSemana', 0]; yield ['setDiaSemana', 8]; yield ['setDiaSemana', 1];
        yield ['setDiaMes', 0]; yield ['setDiaMes', 32]; yield ['setDiaMes', 1];
        yield ['setPlazoMinutos', 0]; yield ['setPlazoMinutos', -1]; yield ['setPlazoMinutos', 2147483648];
        yield ['setHoraPrevista', null]; yield ['setFrecuencia', FrecuenciaTarea::SEMANAL]; yield ['setFrecuencia', FrecuenciaTarea::MENSUAL];
    }

    #[DataProvider('configuracionesInvalidas')]
    public function testValidaConfiguracionEnTareaExistente(string $setter, mixed $valor): void
    {
        $this->tarea->$setter($valor);
        self::assertGreaterThan(0, self::getContainer()->get('validator')->validate($this->tarea)->count());
    }

    public static function horasInvalidas(): iterable
    {
        foreach (['24:00:00', '25:00:00', '09:60:00', '09:00:60', '9:00:00', '09:00', '09:00:00.5'] as $hora) { yield [$hora]; }
    }

    #[DataProvider('horasInvalidas')]
    public function testSerializerRechazaHorasNormalizadas(string $hora): void
    {
        $this->expectException(\Symfony\Component\Serializer\Exception\NotNormalizableValueException::class);
        self::getContainer()->get('serializer')->denormalize(['horaPrevista' => $hora], TareaAPPCC::class);
    }

    public function testSerializerAceptaHoraExactaYNullParaEventos(): void
    {
        $serializer = self::getContainer()->get('serializer');
        $tarea = $serializer->denormalize(['horaPrevista' => '23:59:59'], TareaAPPCC::class);
        self::assertSame('23:59:59', $tarea->getHoraPrevista()->format('H:i:s'));
        self::assertNull($serializer->denormalize(['horaPrevista' => null], TareaAPPCC::class)->getHoraPrevista());
    }

    public function testPlantillaTransfiereCalendarioYRechazaConfiguracionInvalida(): void
    {
        $config = ['planes' => [['nombre' => 'Limpieza', 'tipo' => 'limpieza', 'tareas' => [['nombre' => 'Mesa', 'frecuencia' => 'semanal', 'horaPrevista' => '09:30:00', 'diaSemana' => 2, 'plazoMinutos' => 45]]]]];
        $plantilla = (new PlantillaAPPCC())->setNombre('Semanal')->setTipoActividad(TipoActividad::OBRADOR)->setConfiguracion($config);
        $this->em->persist($plantilla); $this->em->flush();
        self::getContainer()->get(PlantillaAPPCCService::class)->aplicar($plantilla, $this->local);
        $t = $this->em->getRepository(TareaAPPCC::class)->findOneBy(['nombre' => 'Mesa']);
        self::assertSame(2, $t->getDiaSemana()); self::assertSame(45, $t->getPlazoMinutos()); self::assertSame('09:30:00', $t->getHoraPrevista()->format('H:i:s'));
        $config['planes'][0]['tareas'][0]['diaSemana'] = '2';
        $plantilla->setConfiguracion($config); $this->em->flush();
        $this->expectException(\App\Exception\BusinessRuleException::class);
        self::getContainer()->get(PlantillaAPPCCService::class)->aplicar($plantilla, $this->otroLocal);
    }
}
