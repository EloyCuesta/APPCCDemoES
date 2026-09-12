<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TareaAPPCC;
use App\Service\TareaAPPCCService;

/** @implements ProcessorInterface<TareaAPPCC, TareaAPPCC> */
final readonly class TareaAPPCCProcessor implements ProcessorInterface
{
    public function __construct(private TareaAPPCCService $service)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TareaAPPCC
    {
        if (!$data instanceof TareaAPPCC) {
            throw new \InvalidArgumentException('El recurso recibido no es válido.');
        }

        return $this->service->guardarCambios($data);
    }
}
