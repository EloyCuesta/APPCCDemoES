<?php
declare(strict_types=1);
namespace App\Tests;

use App\Enum\TipoEntidadFiscal;
use App\Tests\Support\PostgresTestCase;

final class EntidadFiscalTest extends PostgresTestCase
{
    public function testNormalizacionEIdentidadFiscal(): void
    {
        $fiscal = $this->fiscal(' b99999999 ');
        self::assertSame('B99999999', $fiscal->getNif());
        self::assertCount(0, self::getContainer()->get('validator')->validate($fiscal));
        $this->em->persist($fiscal);
        $this->em->flush();
        self::assertNotNull($fiscal->getId());
        self::assertSame('ana@example.com', $this->usuario->getEmail());
    }

    public function testEmpresaYAutonomoConservanValidaciones(): void
    {
        $fiscal = $this->fiscal('B99999999')->setRazonSocial(null);
        self::assertGreaterThan(0, count(self::getContainer()->get('validator')->validate($fiscal)));
        $fiscal->setTipo(TipoEntidadFiscal::AUTONOMO)->setNombre('Ana')->setApellidos('García');
        self::assertCount(0, self::getContainer()->get('validator')->validate($fiscal));
    }

    public function testNifUnicoEnPostgres(): void
    {
        $this->em->persist($this->fiscal('B12345678'));
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}