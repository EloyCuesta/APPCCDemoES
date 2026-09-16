<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Dto\ArchivoEvidencia;
use App\Enum\TipoEvidencia;
use App\Exception\{BusinessRuleException, EvidenciaStorageException};
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class LocalEvidenciaStorage implements EvidenciaStorageInterface
{
    public function __construct(
        #[Autowire('%env(resolve:APPCC_EVIDENCIAS_DIR)%')] private string $directorio,
        #[Autowire('%kernel.project_dir%')] private string $proyecto,
        #[Autowire('%env(int:APPCC_EVIDENCIAS_MAX_BYTES)%')] private int $maxBytes,
    ) {}

    public function guardarTemporal(UploadedFile $archivo, TipoEvidencia $tipo): ArchivoEvidencia
    {
        if (!$archivo->isValid()) { throw new BusinessRuleException('La subida no es válida o supera el límite de PHP.'); }
        $nombre = $archivo->getClientOriginalPath();
        if ($nombre === '' || preg_match('~[\\\\/:\x00-\x1f\x7f]|\.\.~', $nombre)) {
            throw new BusinessRuleException('El nombre del archivo no puede contener rutas ni caracteres de control.');
        }
        $nombre = mb_substr(trim(preg_replace('/[^\p{L}\p{N} ._()-]/u', '_', $nombre) ?? ''), 0, 200);
        if ($nombre === '') { $nombre = 'evidencia'; }
        $fuente = $archivo->getPathname();
        if (is_link($fuente) || !is_file($fuente)) { throw new BusinessRuleException('El archivo de subida no es válido.'); }
        $tamano = @filesize($fuente);
        if ($this->maxBytes < 1) { throw new EvidenciaStorageException(); }
        if ($tamano === false || $tamano < 1 || $tamano > $this->maxBytes) {
            throw new BusinessRuleException('El archivo está vacío o supera el límite de '.$this->maxBytes.' bytes.');
        }
        $mime = @(new \finfo(FILEINFO_MIME_TYPE))->file($fuente);
        $permitidos = match ($tipo) {
            TipoEvidencia::FOTO => ['image/jpeg', 'image/png', 'image/webp'],
            TipoEvidencia::DOCUMENTO => ['application/pdf'],
            default => [],
        };
        if (!in_array($mime, $permitidos, true) || $mime !== $archivo->getClientMimeType()) {
            throw new BusinessRuleException('El contenido no coincide con el MIME declarado o el tipo de evidencia permitido.');
        }
        if ($tipo === TipoEvidencia::FOTO && (@getimagesize($fuente)['mime'] ?? null) !== $mime) {
            throw new BusinessRuleException('La fotografía no es una imagen válida.');
        }
        $clave = 'temporal/'.bin2hex(random_bytes(32));
        $destino = $this->ruta($clave);
        $entrada = @fopen($fuente, 'rb');
        $salida = @fopen($destino, 'xb');
        if ($entrada === false || $salida === false) {
            if (is_resource($entrada)) { fclose($entrada); }
            if (is_resource($salida)) { fclose($salida); @unlink($destino); }
            throw new EvidenciaStorageException();
        }
        try {
            if (!@chmod($destino, 0600) || @stream_copy_to_stream($entrada, $salida, $this->maxBytes + 1) !== $tamano || !@fflush($salida)) {
                throw new EvidenciaStorageException();
            }
        } catch (\Throwable $e) {
            fclose($entrada); fclose($salida); @unlink($destino);
            throw $e;
        }
        fclose($entrada); fclose($salida);
        $hash = @hash_file('sha256', $destino);
        if ($hash === false) { @unlink($destino); throw new EvidenciaStorageException(); }
        return new ArchivoEvidencia($clave, $nombre, $mime, $tamano, $hash);
    }

    public function nuevaClaveDefinitiva(): string { return 'definitivo/'.bin2hex(random_bytes(32)); }

    public function mover(string $origen, string $destino): void
    {
        $desde = $this->ruta($origen);
        $hasta = $this->ruta($destino);
        if (!is_file($desde) || file_exists($hasta) || !@rename($desde, $hasta)) { throw new EvidenciaStorageException(); }
    }

    public function eliminar(string $clave): void
    {
        $ruta = $this->ruta($clave);
        if (file_exists($ruta) && (!is_file($ruta) || !@unlink($ruta))) { throw new EvidenciaStorageException(); }
    }

    public function abrir(string $clave)
    {
        $ruta = $this->ruta($clave);
        $flujo = is_file($ruta) ? @fopen($ruta, 'rb') : false;
        if ($flujo === false) { throw new EvidenciaStorageException(); }
        return $flujo;
    }

    public function verificar(string $clave, int $tamano, string $hash): bool
    {
        $flujo = $this->abrir($clave);
        try {
            $ctx = hash_init('sha256');
            $bytes = hash_update_stream($ctx, $flujo);
            return $bytes === $tamano && hash_equals($hash, hash_final($ctx));
        } finally { fclose($flujo); }
    }

    public function listar(string $zona): iterable
    {
        if (!in_array($zona, ['temporal', 'definitivo'], true)) { throw new EvidenciaStorageException(); }
        $carpeta = dirname($this->ruta($zona.'/'.str_repeat('0', 64)));
        try { $archivos = new \DirectoryIterator($carpeta); }
        catch (\UnexpectedValueException) { throw new EvidenciaStorageException(); }
        foreach ($archivos as $archivo) {
            if ($archivo->isDot()) { continue; }
            // También informa nombres extraños/enlaces sin abrirlos: el verificador los cuenta como huérfanos.
            yield $zona.'/'.$archivo->getFilename();
        }
    }

    private function ruta(string $clave): string
    {
        if (!preg_match('~^(temporal|definitivo)/[a-f0-9]{64}$~D', $clave)) { throw new EvidenciaStorageException(); }
        $raiz = str_replace('\\', '/', $this->directorio);
        if (!preg_match('~^(?:[A-Za-z]:/|/)~', $raiz) || preg_match('~(?:^|/)\.\.?(?:/|$)~', $raiz)
            || str_starts_with($raiz, '//') || str_contains($raiz, "\0")) { throw new EvidenciaStorageException(); }
        $raiz = rtrim($raiz, '/');
        $public = str_replace('\\', '/', realpath($this->proyecto.'/public') ?: $this->proyecto.'/public');
        if ($this->dentro($raiz, $public)) { throw new EvidenciaStorageException(); }
        $this->carpetaSegura($raiz);
        $real = str_replace('\\', '/', realpath($raiz) ?: '');
        if ($real === '' || $this->dentro($real, $public)) { throw new EvidenciaStorageException(); }
        $carpeta = $real.'/'.explode('/', $clave)[0];
        $this->carpetaSegura($carpeta);
        $ruta = $real.'/'.$clave;
        clearstatcache(true, $ruta);
        if (is_link($ruta) || (file_exists($ruta) && !is_file($ruta))) { throw new EvidenciaStorageException(); }
        return $ruta;
    }

    private function dentro(string $ruta, string $padre): bool
    {
        if (PHP_OS_FAMILY === 'Windows') { $ruta = strtolower($ruta); $padre = strtolower($padre); }
        return $ruta === $padre || str_starts_with($ruta, rtrim($padre, '/').'/');
    }

    private function carpetaSegura(string $ruta): void
    {
        clearstatcache(true, $ruta);
        if (is_link($ruta)) { throw new EvidenciaStorageException(); }
        $padre = dirname($ruta);
        if ($padre !== $ruta && $padre !== '.') { $this->carpetaSegura($padre); }
        if (is_dir($ruta)) {
            // Rechaza también junctions/reparse points que resuelvan fuera de la ruta prevista.
            $real = str_replace('\\', '/', realpath($ruta) ?: '');
            if (strcasecmp(rtrim($real, '/'), rtrim(str_replace('\\', '/', $ruta), '/')) !== 0) { throw new EvidenciaStorageException(); }
            return;
        }
        if (!@mkdir($ruta, 0700) && !is_dir($ruta)) { throw new EvidenciaStorageException(); }
        if (is_link($ruta)) { throw new EvidenciaStorageException(); }
    }
}
