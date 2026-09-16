<?php

declare(strict_types=1);

namespace App\Dto;

/** Solo datos del control: la identidad y la auditoría proceden del servidor. */
final class CrearRegistroInput
{
    public string $tareaProgramada = '';
    public ?string $valorNumerico = null;
    public ?array $datos = null;
    public ?bool $conforme = null;
    public ?string $observaciones = null;
    // Fecha del control (distinta de createdAt y confirmadoAt), sujeta a las reglas temporales.
    public ?string $fechaHora = null;
    public array $evidencias = [];
    public mixed $confirmarRegistro = false;
}
