<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class ResultadoCicloOperativo
{
    public int $tareasAnalizadas = 0;
    public int $creadas = 0;
    public int $existentes = 0;
    public int $ignoradas = 0;
    public int $vencidas = 0;
    /** @var list<string> */
    public array $ignoradasDetalle = [];
    /** @var list<array{fase: string, tarea: ?int, establecimiento: ?int, tipo: string, mensaje: string}> */
    public array $errores = [];

    public function error(string $fase, ?int $tarea, ?int $establecimiento, \Throwable $error): void
    {
        $this->errores[] = ['fase' => $fase, 'tarea' => $tarea, 'establecimiento' => $establecimiento,
            'tipo' => $error::class, 'mensaje' => $error->getMessage()];
    }
}
