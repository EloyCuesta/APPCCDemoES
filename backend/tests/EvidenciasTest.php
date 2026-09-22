<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\{Evidencia, HistorialIncidencia, RegistroAPPCC, SubidaTemporalEvidencia, UsuarioEstablecimiento};
use App\Enum\{RolEstablecimiento, TipoEvidencia};
use App\Exception\{BusinessRuleException, EvidenciaStorageException};
use App\Service\{RegistroAPPCCService, SubidaEvidenciaService};
use App\Service\Storage\{EvidenciaStorageInterface, LocalEvidenciaStorage};
use App\Tests\Support\{EvidenciaFixtures, PostgresTestCase};
use Doctrine\ORM\Event\PostPersistEventArgs;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class EvidenciasTest extends PostgresTestCase
{
    private function subir(string $ext = 'png', ?\App\Entity\Usuario $usuario = null, ?\App\Entity\Establecimiento $local = null): array
    {
        $tipo = $ext === 'pdf' ? TipoEvidencia::DOCUMENTO : TipoEvidencia::FOTO;
        return self::getContainer()->get(SubidaEvidenciaService::class)->subir(EvidenciaFixtures::archivo($ext), $tipo, $usuario ?? $this->usuario, $local ?? $this->local);
    }

    private function entrada(string $ext = 'png'): array { return ['token' => $this->subir($ext)['token'], 'tipo' => $ext === 'pdf' ? 'documento' : 'foto']; }
    private function storage(): EvidenciaStorageInterface { return self::getContainer()->get(EvidenciaStorageInterface::class); }
    private function crear(array $entradas, string $valor = '9', mixed $confirmar = false): RegistroAPPCC
    {
        return self::getContainer()->get(RegistroAPPCCService::class)->registrar($this->registro($this->programar(), $valor), $entradas, $confirmar);
    }

    #[DataProvider('formatos')]
    public function testSubidaCalculaMetadatosYNoCreaEvidencia(string $ext, string $mime): void
    {
        $r = $this->subir($ext);
        self::assertSame($mime, $r['mimeType']);
        self::assertSame(filesize(__DIR__.'/Fixtures/evidencia.'.$ext), $r['tamanoBytes']);
        self::assertSame($this->clock->now()->modify('+1 hour')->format(DATE_ATOM), $r['expiresAt']);
        self::assertSame(0, $this->em->getRepository(Evidencia::class)->count([]));
        $subida = $this->em->getRepository(SubidaTemporalEvidencia::class)->findOneBy([]);
        self::assertSame($this->usuario, $subida->getUsuario());
        self::assertSame($this->local, $subida->getEstablecimiento());
        self::assertTrue($this->storage()->verificar($subida->getStorageKey(), $r['tamanoBytes'], hash_file('sha256', __DIR__.'/Fixtures/evidencia.'.$ext)));
        self::assertSame(hash('sha256', $r['token']), $this->em->getConnection()->fetchOne('SELECT token_hash FROM subida_temporal_evidencia'));
    }
    public static function formatos(): array { return [['jpg', 'image/jpeg'], ['png', 'image/png'], ['webp', 'image/webp'], ['pdf', 'application/pdf']]; }

    #[DataProvider('mimeInvalido')]
    public function testRechazaMimeFalsoYTipoIncompatible(string $ext, string $mime, TipoEvidencia $tipo): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->storage()->guardarTemporal(EvidenciaFixtures::archivo($ext, $mime), $tipo);
    }
    public static function mimeInvalido(): array { return [['png', 'image/jpeg', TipoEvidencia::FOTO], ['pdf', 'image/png', TipoEvidencia::FOTO], ['png', 'image/png', TipoEvidencia::DOCUMENTO], ['pdf', 'application/pdf', TipoEvidencia::FIRMA]]; }

    #[DataProvider('contenidosProhibidos')]
    public function testRechazaHtmlSvgYEjecutables(string $contenido, string $mime): void
    {
        $ruta = dirname(__DIR__).'/var/prohibido-'.bin2hex(random_bytes(8));
        file_put_contents($ruta, $contenido);
        try {
            $this->expectException(BusinessRuleException::class);
            $this->storage()->guardarTemporal(new \Symfony\Component\HttpFoundation\File\UploadedFile($ruta, 'foto.jpg', $mime, test: true), TipoEvidencia::FOTO);
        } finally { unlink($ruta); }
    }
    public static function contenidosProhibidos(): array { return [['<html><script>alert(1)</script></html>', 'text/html'], ['<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'image/svg+xml'], ["MZ\0\0ejecutable", 'image/jpeg']]; }

    public function testLimiteConfigurable(): void
    {
        $storage = new LocalEvidenciaStorage($this->evidenciasDir, dirname(__DIR__), 4);
        $this->expectException(BusinessRuleException::class);
        $storage->guardarTemporal(EvidenciaFixtures::archivo(), TipoEvidencia::FOTO);
    }

    #[DataProvider('rutasInvalidas')]
    public function testRechazaNombresConRutas(string $nombre): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->storage()->guardarTemporal(EvidenciaFixtures::archivo(nombre: $nombre), TipoEvidencia::FOTO);
    }
    public static function rutasInvalidas(): array { return [['../foto.png'], ['/tmp/foto.png'], ['C:\\foto.png'], ['carpeta/foto.png'], ["foto\r\n.png"]]; }

    #[DataProvider('clavesInvalidas')]
    public function testStorageNuncaResuelveClavesExternas(string $clave): void
    {
        $this->expectException(EvidenciaStorageException::class);
        $this->storage()->abrir($clave);
    }
    public static function clavesInvalidas(): array { return [['../.env'], ['/etc/passwd'], ['C:/Windows/win.ini'], ['definitivo/../.env'], ['temporal/'.str_repeat('a', 63)], ['temporal/'.str_repeat('a', 64).'/x']]; }

    public function testRechazaRaizPublica(): void
    {
        $this->expectException(EvidenciaStorageException::class);
        (new LocalEvidenciaStorage(dirname(__DIR__).'/public/evidencias', dirname(__DIR__), 10000))->guardarTemporal(EvidenciaFixtures::archivo(), TipoEvidencia::FOTO);
    }

    public function testRechazaCarpetasSimbolicasOJunctions(): void
    {
        $fs = new \Symfony\Component\Filesystem\Filesystem();
        $fs->mkdir([$this->evidenciasDir, $this->evidenciasDir.'/destino']);
        $enlace = $this->evidenciasDir.'/temporal';
        if (PHP_OS_FAMILY === 'Windows') {
            $quote = static fn ($s) => "'".str_replace("'", "''", $s)."'";
            $p = new \Symfony\Component\Process\Process(['powershell', '-NoProfile', '-NonInteractive', '-Command', 'New-Item -ItemType Junction -Path '.$quote($enlace).' -Target '.$quote($this->evidenciasDir.'/destino').' | Out-Null']);
            self::assertSame(0, $p->run(), $p->getErrorOutput());
        } else { self::assertTrue(symlink($this->evidenciasDir.'/destino', $enlace)); }
        try {
            $this->expectException(EvidenciaStorageException::class);
            $this->storage()->guardarTemporal(EvidenciaFixtures::archivo(), TipoEvidencia::FOTO);
        } finally {
            if (PHP_OS_FAMILY === 'Windows') { rmdir($enlace); } else { unlink($enlace); }
        }
    }

    public function testSubidaConFalloSqlEliminaSuArchivo(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement('ALTER TABLE subida_temporal_evidencia ADD CONSTRAINT test_subida_falla CHECK (false)');
        try {
            try { $this->subir(); self::fail('Debe revertirse la subida.'); }
            catch (EvidenciaStorageException) { self::assertTrue(true); }
            self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM subida_temporal_evidencia'));
            self::assertSame([], iterator_to_array($this->storage()->listar('temporal')));
        } finally { $db->executeStatement('ALTER TABLE subida_temporal_evidencia DROP CONSTRAINT test_subida_falla'); }
    }

    public function testArchivoTemporalAlteradoNoSatisfaceFotoObligatoria(): void
    {
        $this->local->getConfiguracion()->setRequiereFotoNoConforme(true); $this->em->flush();
        $entrada = $this->entrada();
        $s = $this->em->getRepository(SubidaTemporalEvidencia::class)->findOneBy([]);
        file_put_contents($this->evidenciasDir.'/'.$s->getStorageKey(), 'alterado');
        $this->expectException(EvidenciaStorageException::class);
        $this->crear([$entrada]);
    }

    public function testRegistroNoConformeConFotoFirmaEIncidencia(): void
    {
        $this->local->getConfiguracion()->setRequiereFotoNoConforme(true)->setRequiereFirmaRegistro(true);
        $this->em->flush();
        $r = $this->crear([$this->entrada()], confirmar: true);
        self::assertFalse($r->isConforme());
        self::assertSame($this->usuario, $r->getConfirmadoPor());
        self::assertSame($this->clock->now()->getTimestamp(), $r->getConfirmadoAt()->getTimestamp());
        self::assertSame('1', $r->getVersionDeclaracionFirma());
        self::assertCount(1, $r->getIncidencias());
        self::assertCount(1, $r->getEvidencias());
        $e = $r->getEvidencias()->first();
        self::assertSame($this->usuario, $e->getSubidaPor());
        self::assertTrue($this->storage()->verificar($e->getStorageKey(), $e->getTamanoBytes(), $e->getHashSha256()));
        self::assertSame([], iterator_to_array($this->storage()->listar('temporal')));
        self::assertNotNull($this->em->getRepository(SubidaTemporalEvidencia::class)->findOneBy([])->getConsumidaAt());
    }

    #[DataProvider('sinFotoPermitida')]
    public function testSoloConformesSinFotoInclusoConConfiguracionManipulada(string $valor, bool $obligatoria): void
    {
        $programada = $this->programar();
        // Incluso una configuración heredada/alterada en memoria no exime de aportar foto.
        $config = $this->local->getConfiguracion();
        (new \ReflectionProperty($config, 'requiereFotoNoConforme'))->setValue($config, $obligatoria);
        if ($valor === '9') {
            $this->expectException(BusinessRuleException::class);
            $this->expectExceptionMessage('fotografía');
            self::getContainer()->get(RegistroAPPCCService::class)->registrar($this->registro($programada, $valor));
            return;
        }
        // Restaurar el flag antes del flush: PostgreSQL tampoco permite desactivarlo.
        $config->setRequiereFotoNoConforme(true);
        self::assertNotNull($this->crear([], $valor)->getId());
    }
    public static function sinFotoPermitida(): array { return [['3', true], ['3', false], ['9', false], ['9', true]]; }

    #[DataProvider('sinFotoValida')]
    public function testFotoObligatoriaNoSeSustituyePorPdf(bool $pdf): void
    {
        $this->local->getConfiguracion()->setRequiereFotoNoConforme(true); $this->em->flush();
        $entrada = $pdf ? [$this->entrada('pdf')] : [];
        try { $this->crear($entrada); self::fail('Debe exigir foto.'); }
        catch (BusinessRuleException $e) { self::assertStringContainsString('fotografía', $e->getMessage()); }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM registro_appcc'));
        self::assertSame('pendiente', $this->em->getConnection()->fetchOne('SELECT estado FROM tarea_programada'));
    }
    public static function sinFotoValida(): array { return [[false], [true]]; }

    #[DataProvider('confirmacionesInvalidas')]
    public function testFirmaRequeridaExigeTrueExacto(mixed $confirmacion): void
    {
        $this->local->getConfiguracion()->setRequiereFirmaRegistro(true); $this->em->flush();
        $this->expectException(BusinessRuleException::class);
        $this->crear([], '3', $confirmacion);
    }
    public static function confirmacionesInvalidas(): array { return [[false], [null], [1], ['true'], ['false']]; }

    #[DataProvider('confirmacionesOpcionales')]
    public function testConfirmacionVoluntaria(bool $confirmar): void
    {
        $r = $this->crear([], '3', $confirmar);
        self::assertSame($confirmar, $r->getConfirmadoAt() !== null);
        self::assertSame($confirmar ? $this->usuario : null, $r->getConfirmadoPor());
    }
    public static function confirmacionesOpcionales(): array { return [[true], [false]]; }

    #[DataProvider('tokensInvalidos')]
    public function testNoAceptaTokensCaducadosConsumidosDuplicadosOAjenos(string $caso): void
    {
        $this->local->getConfiguracion()->setRequiereFotoNoConforme(true); $this->em->flush();
        $entrada = $this->entrada();
        if ($caso === 'caducado') { $this->clock->sleep(3600); }
        if ($caso === 'usuario') {
            $m = (new UsuarioEstablecimiento())->setUsuario($this->otroUsuario)->setEstablecimiento($this->local)->setRol(RolEstablecimiento::TRABAJADOR);
            $this->em->persist($m); $this->em->flush();
            $entrada['token'] = $this->subir(usuario: $this->otroUsuario)['token'];
        }
        if ($caso === 'tenant') {
            $m = (new UsuarioEstablecimiento())->setUsuario($this->usuario)->setEstablecimiento($this->otroLocal)->setRol(RolEstablecimiento::TRABAJADOR);
            $this->em->persist($m); $this->em->flush();
            $entrada['token'] = $this->subir(local: $this->otroLocal)['token'];
        }
        if ($caso === 'consumido') { $this->crear([$entrada]); $this->clock->sleep(60); }
        if ($caso === 'tipo') { $entrada['tipo'] = 'documento'; }
        $entradas = $caso === 'duplicado' ? [$entrada, $entrada] : [$entrada];
        $this->expectException(BusinessRuleException::class);
        $this->crear($entradas);
    }
    public static function tokensInvalidos(): array { return [['caducado'], ['usuario'], ['tenant'], ['consumido'], ['duplicado'], ['tipo']]; }

    public function testRollbackSqlRestauraTemporalesYNoDejaEvidencias(): void
    {
        $entradas = [$this->entrada(), $this->entrada('pdf')];
        $listener = new class {
            public function postPersist(PostPersistEventArgs $args): void
            {
                if ($args->getObject() instanceof HistorialIncidencia) { throw new \RuntimeException('Fallo simulado'); }
            }
        };
        $this->em->getEventManager()->addEventListener(['postPersist'], $listener);
        try { $this->crear($entradas, confirmar: true); self::fail('Debe fallar.'); }
        catch (EvidenciaStorageException) { self::assertTrue(true); }
        $db = $this->em->getConnection();
        foreach (['registro_appcc', 'evidencia', 'incidencia', 'historial_incidencia'] as $tabla) { self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM '.$tabla)); }
        self::assertSame('pendiente', $db->fetchOne('SELECT estado FROM tarea_programada'));
        self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM subida_temporal_evidencia WHERE consumida_at IS NOT NULL'));
        self::assertSame([], iterator_to_array($this->storage()->listar('definitivo')));
        self::assertCount(2, iterator_to_array($this->storage()->listar('temporal')));
    }

    public function testFalloDelSegundoMovimientoCompensaElPrimero(): void
    {
        $real = new LocalEvidenciaStorage($this->evidenciasDir, dirname(__DIR__), 10485760);
        $storage = $this->createMock(EvidenciaStorageInterface::class);
        $storage->method('guardarTemporal')->willReturnCallback($real->guardarTemporal(...));
        $storage->method('verificar')->willReturnCallback($real->verificar(...));
        $storage->method('nuevaClaveDefinitiva')->willReturnCallback($real->nuevaClaveDefinitiva(...));
        $numero = 0;
        $storage->expects(self::exactly(3))->method('mover')->willReturnCallback(static function ($desde, $hasta) use ($real, &$numero): void {
            if (str_starts_with($desde, 'temporal/') && ++$numero === 2) { throw new EvidenciaStorageException(); }
            $real->mover($desde, $hasta);
        });
        self::getContainer()->set(EvidenciaStorageInterface::class, $storage);
        $entradas = [$this->entrada(), $this->entrada('pdf')];
        try { $this->crear($entradas); self::fail('Debe fallar el segundo movimiento.'); }
        catch (EvidenciaStorageException) { self::assertTrue(true); }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM evidencia'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM registro_appcc'));
        self::assertCount(2, iterator_to_array($real->listar('temporal')));
        self::assertSame([], iterator_to_array($real->listar('definitivo')));
    }

    public function testLimpiezaRespetaActivosYDefinitivos(): void
    {
        $this->crear([$this->entrada()]);
        $this->entrada();
        $this->clock->sleep(3600);
        $this->entrada();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:evidencias:limpiar-temporales'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('eliminadas: 2', $tester->getDisplay());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM subida_temporal_evidencia'));
        self::assertCount(1, iterator_to_array($this->storage()->listar('temporal')));
        self::assertCount(1, iterator_to_array($this->storage()->listar('definitivo')));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('eliminadas: 0', $tester->getDisplay());
    }

    #[DataProvider('inmutables')]
    public function testSqlImpideModificarOBorrarHistoricos(string $tabla, string $operacion): void
    {
        $this->crear([$this->entrada()], confirmar: true);
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->em->getConnection()->executeStatement($operacion === 'DELETE' ? 'DELETE FROM '.$tabla : 'UPDATE '.$tabla.' SET '.($tabla === 'evidencia' ? "nombre_original = 'otro'" : 'confirmado_at = NULL, confirmado_por_id = NULL'));
    }
    public static function inmutables(): array { return [['registro_appcc', 'UPDATE'], ['registro_appcc', 'DELETE'], ['evidencia', 'UPDATE'], ['evidencia', 'DELETE']]; }

    public function testConfirmacionNoSeAnadeDespues(): void
    {
        $r = $this->crear([], '3');
        $this->expectException(BusinessRuleException::class);
        $r->confirmar($this->usuario, $this->clock->now());
    }

    public function testMigracionNoRevierteConfirmacionesExistentes(): void
    {
        $r = $this->crear([], '3', true);
        require_once dirname(__DIR__).'/migrations/Version20260916090000.php';
        $db = $this->em->getConnection();
        $m = new \DoctrineMigrations\Version20260916090000($db, new \Psr\Log\NullLogger());
        $m->down(new \Doctrine\DBAL\Schema\Schema());
        try {
            $db->transactional(function () use ($m, $db): void { foreach ($m->getSql() as $q) { $db->executeStatement($q->getStatement()); } });
            self::fail('No se debe eliminar una confirmación.');
        } catch (\Doctrine\DBAL\Exception\DriverException $e) { self::assertStringContainsString('Reversión bloqueada', $e->getMessage()); }
        self::assertSame($this->usuario->getId(), $db->fetchOne('SELECT confirmado_por_id FROM registro_appcc WHERE id = ?', [$r->getId()]));
    }

    #[DataProvider('firmasSqlInvalidas')]
    public function testCheckSqlFirmaCoherente(bool $fecha, string $firmante): void
    {
        $p = $this->programar();
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->em->getConnection()->executeStatement('INSERT INTO registro_appcc (tarea_programada_id, establecimiento_id, usuario_id, fecha_hora, conforme, created_at, confirmado_at, confirmado_por_id) VALUES (?, ?, ?, CURRENT_TIMESTAMP, true, CURRENT_TIMESTAMP, ?, ?)',
            [$p->getId(), $this->local->getId(), $this->usuario->getId(), $fecha ? '2026-09-12 10:00:00' : null, match ($firmante) { 'propio' => $this->usuario->getId(), 'ajeno' => $this->otroUsuario->getId(), default => null }]);
    }
    public static function firmasSqlInvalidas(): array { return [[true, 'null'], [false, 'propio'], [true, 'ajeno']]; }

    public function testVerificadorDetectaAusenciaHuerfanosYHashSinBorrar(): void
    {
        $e = $this->crear([$this->entrada()])->getEvidencias()->first();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:evidencias:verificar'));
        self::assertSame(0, $tester->execute([]));
        $ruta = $this->evidenciasDir.'/'.$e->getStorageKey();
        $bytes = file_get_contents($ruta);
        file_put_contents($ruta, str_repeat('x', strlen($bytes))); // Igual tamaño, hash distinto.
        self::assertSame(1, $tester->execute([]));
        file_put_contents($ruta, $bytes.'extra'); // Tamaño distinto.
        self::assertSame(1, $tester->execute([]));
        unlink($ruta);
        $huerfano = $this->storage()->guardarTemporal(EvidenciaFixtures::archivo(), TipoEvidencia::FOTO);
        $clave = $this->storage()->nuevaClaveDefinitiva(); $this->storage()->mover($huerfano->storageKey, $clave);
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('inconsistentes: 1. Archivos definitivos sin fila: 1', $tester->getDisplay());
        self::assertFileExists($this->evidenciasDir.'/'.$clave);
        self::assertSame(1, $this->em->getRepository(Evidencia::class)->count([]));
    }
}
