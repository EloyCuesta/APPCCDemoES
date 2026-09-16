<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class EstablecimientoSesionOutput
{
    public function __construct(
        public int $id,
        public string $iri,
        public string $nombre,
        public string $tipoActividad,
        public EntidadFiscalSesionOutput $entidadFiscal,
    ) {}
}
