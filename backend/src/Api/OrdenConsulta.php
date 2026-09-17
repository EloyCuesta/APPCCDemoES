<?php

declare(strict_types=1);

namespace App\Api;

use ApiPlatform\Metadata\QueryParameter;
use App\Doctrine\Filter\OrdenAgendaFilter;

final class OrdenConsulta extends QueryParameter
{
    public function __construct(string $property)
    {
        parent::__construct(property: $property, filter: OrdenAgendaFilter::class, castToArray: false, castToNativeType: false,
            schema: ['type' => 'string', 'enum' => ['asc', 'desc', 'ASC', 'DESC']],
            description: 'Orden por '.$property.' con desempate por id ascendente.');
    }
}
