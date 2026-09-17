<?php

declare(strict_types=1);

namespace App\Api;

use ApiPlatform\Metadata\QueryParameter;
use App\Doctrine\Filter\IriTenantFilter;

final class IriConsulta extends QueryParameter
{
    public function __construct(string $property, string $ruta)
    {
        parent::__construct(property: $property, filter: IriTenantFilter::class, castToArray: false, castToNativeType: false,
            schema: ['type' => 'string', 'example' => $ruta.'/1'],
            description: 'IRI de un recurso del establecimiento seleccionado: '.$ruta.'/{id}. Ajeno o inexistente: 404.');
    }
}
