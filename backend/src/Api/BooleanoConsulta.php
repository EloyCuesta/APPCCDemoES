<?php

declare(strict_types=1);

namespace App\Api;

use ApiPlatform\Metadata\QueryParameter;
use App\Doctrine\Filter\BooleanoConsultaFilter;

final class BooleanoConsulta extends QueryParameter
{
    public function __construct(string $property)
    {
        parent::__construct(property: $property, filter: BooleanoConsultaFilter::class, castToArray: false, castToNativeType: false,
            schema: ['type' => 'string', 'enum' => ['true', 'false', '1', '0']],
            description: 'Igualdad booleana: true/1 o false/0. Omitir para incluir ambos valores.');
    }
}
