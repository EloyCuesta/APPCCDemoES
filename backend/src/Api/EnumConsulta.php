<?php

declare(strict_types=1);

namespace App\Api;

use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Metadata\QueryParameter;

final class EnumConsulta extends QueryParameter
{
    /** @param class-string<\BackedEnum> $enum */
    public function __construct(string $property, string $enum)
    {
        parent::__construct(property: $property, filter: new ExactFilter(), castToArray: false, castToNativeType: false,
            schema: ['type' => 'string', 'enum' => array_column($enum::cases(), 'value')],
            description: 'Igualdad exacta; admite un único valor del catálogo.');
    }
}
