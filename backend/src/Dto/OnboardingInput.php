<?php
declare(strict_types=1);
namespace App\Dto;

use ApiPlatform\Metadata\{ApiResource, Post};
use App\State\Processor\OnboardingProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(operations: [new Post(uriTemplate: '/onboarding', status: 201, output: AltaUsuarioOutput::class,
    read: false, security: 'true', securityPostDenormalize: 'true', processor: OnboardingProcessor::class)],
    denormalizationContext: ['allow_extra_attributes' => false])]
final class OnboardingInput
{
    #[Assert\NotNull, Assert\Valid]
    public ?EntidadFiscalInput $entidadFiscal = null;
    #[Assert\NotNull, Assert\Valid]
    public ?EstablecimientoInput $establecimiento = null;
    #[Assert\NotNull, Assert\Valid]
    public ?AdministradorInput $administrador = null;
}
