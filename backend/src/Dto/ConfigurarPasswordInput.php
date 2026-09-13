<?php
declare(strict_types=1);
namespace App\Dto;

use ApiPlatform\Metadata\{ApiResource, Post};
use App\State\Processor\ConfigurarPasswordProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(operations: [new Post(uriTemplate: '/auth/configurar-password', status: 204, output: false,
    read: false, security: 'true', securityPostDenormalize: 'true', processor: ConfigurarPasswordProcessor::class)],
    denormalizationContext: ['allow_extra_attributes' => false])]
final class ConfigurarPasswordInput
{
    #[Assert\NotBlank, Assert\Length(max: 128)]
    public string $token = '';
    #[Assert\NotBlank, Assert\Length(min: 12, max: 72, countUnit: Assert\Length::COUNT_BYTES)]
    public string $password = '';
    #[Assert\EqualTo(propertyPath: 'password', message: 'La confirmación no coincide.')]
    public string $passwordConfirmation = '';
}
