<?php

declare(strict_types=1);

namespace App\Exception;

/** Conserva el total confirmado antes de un fallo en un lote posterior. */
#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final class DeteccionVencidasException extends \RuntimeException
{
    public function __construct(public readonly int $confirmadas, \Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
