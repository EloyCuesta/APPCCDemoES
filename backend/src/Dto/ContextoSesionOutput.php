<?php

declare(strict_types=1);

namespace App\Dto;

/** Contrato de sesión independiente de la serialización de entidades Doctrine. */
final readonly class ContextoSesionOutput
{
    /** @param list<MembresiaSesionOutput> $membresias */
    public function __construct(
        public int $id,
        public string $nombre,
        public string $apellidos,
        public string $email,
        public array $membresias,
        public ?int $establecimientoPredeterminadoId,
    ) {}
}
