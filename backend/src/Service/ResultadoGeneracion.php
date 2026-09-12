<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ResultadoGeneracion
{
    /**
     * ocurrenciasCalculadas = creadas + existentes, incluidas las resueltas bajo bloqueo.
     * Una tarea ignorada no aporta ocurrencias; las excluidas por la consulta no se analizan.
     * @param list<string> $ignoradasDetalle
     */
    public function __construct(
        public int $tareasAnalizadas,
        public int $ocurrenciasCalculadas,
        public int $creadas,
        public int $existentes,
        public int $ignoradas,
        public array $ignoradasDetalle = [],
    ) {
    }
}
