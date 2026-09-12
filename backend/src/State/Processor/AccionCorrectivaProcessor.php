<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\AccionCorrectiva;
use App\Service\AccionCorrectivaService;

/** @implements ProcessorInterface<AccionCorrectiva, AccionCorrectiva> */
final readonly class AccionCorrectivaProcessor implements ProcessorInterface
{
    public function __construct(private AccionCorrectivaService $service)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AccionCorrectiva
    {
        if (!$data instanceof AccionCorrectiva) {
            throw new \InvalidArgumentException('El recurso recibido no es válido.');
        }

        return $this->service->anadir($data);
    }
}
