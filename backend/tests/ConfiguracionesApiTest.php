<?php
declare(strict_types=1);
namespace App\Tests;

use App\Tests\Support\UsuariosApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConfiguracionesApiTest extends UsuariosApiTestCase
{
    #[DataProvider('configuraciones')]
    public function testSoloConsultaActualizacionYOrigenInmutable(string $uri, string $tabla, string $campo, string $parent, string $editable, mixed $valor): void
    {
        $db = $this->em->getConnection(); $id = (int) $db->fetchOne('SELECT id FROM '.$tabla.' ORDER BY id LIMIT 1');
        self::assertContains($this->api('POST', '/api/'.$uri, [])->getStatusCode(), [404, 405]);
        self::assertSame(200, $this->api('GET', '/api/'.$uri.'/'.$id)->getStatusCode());
        $r = $this->api('PATCH', '/api/'.$uri.'/'.$id, [$editable => $valor]);
        self::assertSame(200, $r->getStatusCode(), $r->getContent());
        self::assertSame($valor, $this->json($r)[$editable]);
        $r = $this->api('PATCH', '/api/'.$uri.'/'.$id, [$campo => '/api/'.$parent.'/2']);
        self::assertContains($r->getStatusCode(), [400, 403, 404]);
        self::assertSame(1, $db->fetchOne('SELECT '.($campo === 'entidadFiscal' ? 'entidad_fiscal_id' : 'establecimiento_id').' FROM '.$tabla.' WHERE id = ?', [$id]));
        $db->beginTransaction();
        try {
            $cols = $db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name <> 'id' ORDER BY ordinal_position", [$tabla]);
            $campos = implode(', ', $cols);
            $db->executeStatement('INSERT INTO '.$tabla.' ('.$campos.') SELECT '.$campos.' FROM '.$tabla.' WHERE id = ?', [$id]);
            self::fail('Debe existir UNIQUE para la configuración.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) { self::assertSame('23505', $e->getSQLState()); }
        finally { $db->rollBack(); }
    }
    public static function configuraciones(): array
    {
        return [['configuraciones-entidad-fiscal', 'configuracion_entidad_fiscal', 'entidadFiscal', 'entidades-fiscales', 'idioma', 'en'],
            ['configuraciones-establecimiento', 'configuracion_establecimiento', 'establecimiento', 'establecimientos', 'permiteRegistrosAtrasados', true]];
    }
}
