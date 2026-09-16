<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class MembresiaSesionOutput
{
    public function __construct(
        public int $id,
        public string $iri,
        public string $rol,
        public EstablecimientoSesionOutput $establecimiento,
    ) {}
}
