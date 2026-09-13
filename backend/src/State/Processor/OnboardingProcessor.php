<?php
declare(strict_types=1);
namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\OnboardingInput;
use App\Dto\AltaUsuarioOutput;
use App\Service\OnboardingService;

/** @implements ProcessorInterface<OnboardingInput, AltaUsuarioOutput> */
final readonly class OnboardingProcessor implements ProcessorInterface
{
    public function __construct(private OnboardingService $service) {}
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AltaUsuarioOutput
    {
        if (!$data instanceof OnboardingInput) { throw new \InvalidArgumentException('Entrada no válida.'); }
        return $this->service->registrarPublico($data);
    }
}

