<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class AplicarPlantillaOutput
{
    public function __construct(
        public int $aplicacionId,
        public int $plantillaId,
        public int $establecimientoId,
        public bool $yaAplicada,
        public \DateTimeImmutable $aplicadaAt,
        public array $creados,
        public array $resultadoInicial,
    ) {}
}
