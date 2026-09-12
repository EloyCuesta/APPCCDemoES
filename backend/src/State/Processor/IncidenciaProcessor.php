<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Incidencia;
use App\Service\IncidenciaService;

/** @implements ProcessorInterface<Incidencia, Incidencia> */
final readonly class IncidenciaProcessor implements ProcessorInterface
{
    public function __construct(private IncidenciaService $service)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Incidencia
    {
        if (!$data instanceof Incidencia) {
            throw new \InvalidArgumentException('El recurso recibido no es válido.');
        }

        return $operation->getMethod() === 'POST' ? $this->service->crearManual($data) : $this->service->guardarCambios($data);
    }
}
