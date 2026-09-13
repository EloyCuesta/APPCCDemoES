<?php
declare(strict_types=1);
namespace App\Dto;

use ApiPlatform\Metadata\{ApiResource, Post};
use App\State\Processor\AceptarInvitacionProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(operations: [new Post(uriTemplate: '/invitaciones/aceptar', status: 200, output: AltaUsuarioOutput::class,
    read: false, security: 'true', securityPostDenormalize: 'true', processor: AceptarInvitacionProcessor::class)],
    denormalizationContext: ['allow_extra_attributes' => false])]
final class AceptarInvitacionInput
{
    #[Assert\NotBlank, Assert\Length(max: 128)]
    public string $token = '';
    // Obligatorios solo para identidades nuevas; nunca sobrescriben a un usuario existente.
    public string $nombre = '';
    public string $apellidos = '';
}
