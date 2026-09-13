<?php
declare(strict_types=1);
namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ConfigurarPasswordInput;
use App\Service\PasswordInicialService;

/** @implements ProcessorInterface<ConfigurarPasswordInput, void> */
final readonly class ConfigurarPasswordProcessor implements ProcessorInterface
{
    public function __construct(private PasswordInicialService $service) {}
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        if (!$data instanceof ConfigurarPasswordInput) { throw new \InvalidArgumentException('Entrada no válida.'); }
        $this->service->configurar($data);
    }
}

