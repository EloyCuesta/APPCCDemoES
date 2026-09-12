<?php
declare(strict_types=1);
namespace App\Tests;

use App\Entity\{EntidadFiscal, Establecimiento, TareaProgramada, PlantillaAPPCC, TareaAPPCC};
use App\Enum\TipoActividad;
use App\Tests\Support\PostgresTestCase;
use App\Service\{PlantillaAPPCCService, OnboardingService};

final class MvpModelTest extends PostgresTestCase
{
    public function testMappingYRelacionesBidireccionales(): void
    {
        self::assertSame([], (new \Doctrine\ORM\Tools\SchemaValidator($this->em))->validateMapping());
        $a = new EntidadFiscal(); $b = new EntidadFiscal(); $local = new Establecimiento();
        $a->addEstablecimiento($local);
        $local->setEntidadFiscal($b);
        self::assertFalse($a->getEstablecimientos()->contains($local));
        self::assertTrue($b->getEstablecimientos()->contains($local));
        $b->removeEstablecimiento($local);
        self::assertNull($local->getEntidadFiscal());
        $p = $this->programar();
        self::assertTrue($this->tarea->getProgramaciones()->contains($p));
        self::assertSame($this->tarea, $p->getTarea());
    }

    public function testFkImpideBorrarDefinicionConRegistro(): void
    {
        $this->registrar();
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->expectExceptionMessage('RESTRICT');
        $this->em->getConnection()->delete('tarea_appcc', ['id' => $this->tarea->getId()]);
    }

    public function testPlantillaCreaDefinicionesSinProgramarOcurrencias(): void
    {
        $plantilla = (new PlantillaAPPCC())->setNombre('Limpieza')->setTipoActividad(TipoActividad::OBRADOR)->setConfiguracion([
            'planes' => [['nombre' => 'Limpieza', 'tipo' => 'limpieza', 'tareas' => [['nombre' => 'Mesa', 'frecuencia' => 'diaria', 'configuracion' => ['tipoRespuesta' => 'boolean']]]]],
        ]);
        $this->em->persist($plantilla); $this->em->flush();
        self::getContainer()->get(PlantillaAPPCCService::class)->aplicar($plantilla, $this->local);
        self::assertSame(2, $this->em->getRepository(TareaAPPCC::class)->count(['establecimiento' => $this->local]));
        self::assertSame(0, $this->em->getRepository(TareaProgramada::class)->count([]));
        $this->expectException(\App\Exception\BusinessRuleException::class);
        self::getContainer()->get(PlantillaAPPCCService::class)->aplicar($plantilla, $this->local);
    }

    public function testRollbackOnboardingConPlantillaInvalida(): void
    {
        $p = (new PlantillaAPPCC())->setNombre('Inválida')->setTipoActividad(TipoActividad::OBRADOR)->setConfiguracion([
            'planes' => [['nombre' => 'Limpieza', 'tipo' => 'limpieza', 'tareas' => [['nombre' => 'Mesa', 'frecuencia' => 'inexistente']]]]],
        );
        $this->em->persist($p); $this->em->flush();
        try {
            self::getContainer()->get(OnboardingService::class)->crearOnboarding($this->fiscal('B99999999'), $this->establecimiento('Nuevo'), $this->usuario, $p);
            self::fail('Debe fallar la plantilla.');
        } catch (\App\Exception\BusinessRuleException) {
            self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM entidad_fiscal'));
            self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM establecimiento'));
        }
    }
}
