<?php
declare(strict_types=1);
namespace App\Security;

use App\Entity\Usuario;
use Symfony\Component\Security\Core\User\{UserCheckerInterface, UserInterface};
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class UsuarioChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof Usuario || !$user->isActivo() || $user->getPassword() === null) {
            throw new CustomUserMessageAccountStatusException('La cuenta no puede iniciar sesión.');
        }
    }
    public function checkPostAuth(UserInterface $user): void {}
}
