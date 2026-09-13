<?php
declare(strict_types=1);
namespace App\Dto;

use App\Entity\Usuario;

final class AdministradorInput
{
    public string $nombre = '';
    public string $apellidos = '';
    public string $email = '';

    public function crear(): Usuario
    {
        return (new Usuario())->setNombre($this->nombre)->setApellidos($this->apellidos)->setEmail($this->email);
    }
}
