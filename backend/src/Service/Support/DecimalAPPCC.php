<?php

declare(strict_types=1);

namespace App\Service\Support;

use App\Exception\BusinessRuleException;

final class DecimalAPPCC
{
    // NUMERIC(12,3): enteros escalados exactos, sin conversiones a float.
    public static function unidades(string $value): int
    {
        if (!preg_match('/^(-?)([0-9]{1,9})(?:\\.([0-9]{1,3}))?$/D', $value, $matches)) {
            throw new BusinessRuleException('El valor debe ser un decimal con hasta 9 cifras enteras y 3 decimales.');
        }

        $scaled = (int) $matches[2] * 1000 + (int) str_pad($matches[3] ?? '', 3, '0');

        return $matches[1] === '-' ? -$scaled : $scaled;
    }
}
