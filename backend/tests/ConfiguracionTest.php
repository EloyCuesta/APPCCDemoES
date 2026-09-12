<?php
declare(strict_types=1);
namespace App\Tests;

use App\Entity\{ConfiguracionEstablecimiento, ConfiguracionEntidadFiscal, RegistroAPPCC};
use App\Tests\Support\PostgresTestCase;
use App\Service\RegistroAPPCCService;
use App\Exception\BusinessRuleException;

final class ConfiguracionTest extends PostgresTestCase
{
    public function testDefaultsYRelacionUnica(): void
    {
        $config = $this->local->getConfiguracion();
        self::assertSame($this->local, $config->getEstablecimiento());
        self::assertFalse($config->isPermiteRegistrosAtrasados());
        self::assertTrue($config->isGeneraIncidenciaAutomatica());
        self::assertTrue($config->isRequiereObservacionNoConforme());
        self::assertSame('Europe/Madrid', $this->local->getEntidadFiscal()->getConfiguracion()->getZonaHoraria());
        $duplicada = (new ConfiguracionEstablecimiento())->setEstablecimiento($this->local);
        $this->em->persist($duplicada);
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testEscenarioAntiguoConMembresiaYFechaPermitida(): void
    {
        // Onboarding crea la membresía; fecha actual y conformidad calculada por el servicio.
        $r = $this->registrar('3');
        $config = $this->local->getConfiguracion();
        $config->setRequiereFotoNoConforme(true)->setRequiereFirmaRegistro(true)->setGeneraIncidenciaAutomatica(false);
        $this->local->getEntidadFiscal()->getConfiguracion()->setDiasConservacionRegistros(1);
        $this->em->flush();
        $this->em->clear();
        $historico = $this->em->find(RegistroAPPCC::class, $r->getId());
        self::assertTrue($historico->isConforme());
        self::assertSame('3.000', $historico->getValorNumerico());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM incidencia'));
    }

    public function testFechaAntiguaSinPermisoEsRechazada(): void
    {
        $p = $this->programar('-2 days');
        $r = $this->registro($p)->setFechaHora($this->clock->now()->modify('-1 day'));
        $this->expectException(BusinessRuleException::class);
        self::getContainer()->get(RegistroAPPCCService::class)->registrar($r);
    }

    public function testValidacionConfiguracion(): void
    {
        $config = $this->local->getConfiguracion()->setMaximoMinutosRegistroAtrasado(-1)->setMinutosAvisoTarea(-1);
        self::assertCount(2, self::getContainer()->get('validator')->validate($config));
        $fiscal = $this->local->getEntidadFiscal()->getConfiguracion()->setZonaHoraria('Zona/Inexistente')->setDiasConservacionRegistros(0);
        self::assertCount(2, self::getContainer()->get('validator')->validate($fiscal));
    }
}