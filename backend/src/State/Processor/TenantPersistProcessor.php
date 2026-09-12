<?php
declare(strict_types=1);
namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Security\TenantAuthorization;
use Symfony\Component\DependencyInjection\Attribute\{AsDecorator, AutowireDecorated};

/** @implements ProcessorInterface<object, object> */
#[AsDecorator('api_platform.doctrine.orm.state.persist_processor')]
final readonly class TenantPersistProcessor implements ProcessorInterface
{
    public function __construct(#[AutowireDecorated] private ProcessorInterface $inner, private TenantAuthorization $authorization) {}
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->authorization->assertWrite($data);
        return $this->inner->process($data, $operation, $uriVariables, $context);
    }
}
