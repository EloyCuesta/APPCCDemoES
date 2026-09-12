<?php
declare(strict_types=1);
namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Evidencia;
use App\Service\EvidenciaService;

/** @implements ProcessorInterface<Evidencia, Evidencia> */
final readonly class EvidenciaProcessor implements ProcessorInterface
{
    public function __construct(private EvidenciaService $service) {}
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Evidencia { return $this->service->anadir($data); }
}
