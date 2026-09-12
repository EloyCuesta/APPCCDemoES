<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\RegistroAPPCC;
use App\Service\RegistroAPPCCService;
use Psr\Clock\ClockInterface;

/** @implements ProcessorInterface<RegistroAPPCC, RegistroAPPCC> */
final readonly class RegistrarControlProcessor implements ProcessorInterface
{
    public function __construct(private RegistroAPPCCService $service, private ClockInterface $clock)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RegistroAPPCC
    {
        if (!$data instanceof RegistroAPPCC) {
            throw new \InvalidArgumentException('El recurso recibido no es válido.');
        }
        $request = $context['request'] ?? null;
        if ($request !== null && !array_key_exists('fechaHora', $request->toArray())) {
            $data->setFechaHora($this->clock->now());
        }

        return $this->service->registrar($data);
    }
}
