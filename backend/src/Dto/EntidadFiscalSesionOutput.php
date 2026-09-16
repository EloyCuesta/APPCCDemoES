<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class EntidadFiscalSesionOutput
{
    public function __construct(public int $id, public string $nombre) {}
}
