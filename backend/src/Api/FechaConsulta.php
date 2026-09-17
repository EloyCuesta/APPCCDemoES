<?php

declare(strict_types=1);

namespace App\Api;

use ApiPlatform\Metadata\QueryParameter;
use App\Doctrine\Filter\FechaConsultaFilter;

final class FechaConsulta extends QueryParameter
{
    public function __construct(string $property)
    {
        parent::__construct(property: $property, filter: FechaConsultaFilter::class, castToArray: false, castToNativeType: false,
            schema: ['type' => 'string', 'format' => 'date-time', 'example' => '2026-09-16T10:00:00Z'],
            description: 'Límite inclusivo ISO 8601: YYYY-MM-DDTHH:MM:SSZ o ±HH:MM, sin fracciones de segundo.');
    }
}
