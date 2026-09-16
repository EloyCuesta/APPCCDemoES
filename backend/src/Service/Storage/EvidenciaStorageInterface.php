<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Dto\ArchivoEvidencia;
use App\Enum\TipoEvidencia;
use Symfony\Component\HttpFoundation\File\UploadedFile;

interface EvidenciaStorageInterface
{
    public function guardarTemporal(UploadedFile $archivo, TipoEvidencia $tipo): ArchivoEvidencia;
    public function nuevaClaveDefinitiva(): string;
    public function mover(string $origen, string $destino): void;
    public function eliminar(string $clave): void;
    /** @return resource Flujo abierto; el llamador debe cerrarlo. */
    public function abrir(string $clave);
    public function verificar(string $clave, int $tamano, string $hash): bool;
    /** @return iterable<string> Claves internas, solo para mantenimiento. */
    public function listar(string $zona): iterable;
}
