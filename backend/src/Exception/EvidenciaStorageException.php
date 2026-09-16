<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** Nunca incluye rutas, claves internas ni la excepción del sistema de archivos. */
final class EvidenciaStorageException extends ServiceUnavailableHttpException
{
    public function __construct()
    {
        parent::__construct(null, 'El archivo de evidencia no está disponible. Se requiere revisar el almacenamiento.');
    }
}
