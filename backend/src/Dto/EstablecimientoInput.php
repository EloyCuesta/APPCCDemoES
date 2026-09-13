<?php
declare(strict_types=1);
namespace App\Dto;

use App\Entity\Establecimiento;
use App\Enum\TipoActividad;

final class EstablecimientoInput
{
    public string $nombre = '';
    public ?TipoActividad $tipoActividad = null;
    public string $direccion = '';
    public string $codigoPostal = '';
    public string $localidad = '';
    public string $provincia = '';
    public ?string $telefono = null;
    public ?string $email = null;
    public ?string $registroSanitario = null;

    public function crear(): Establecimiento
    {
        return (new Establecimiento())->setNombre($this->nombre)->setTipoActividad($this->tipoActividad)
            ->setDireccion($this->direccion)->setCodigoPostal($this->codigoPostal)->setLocalidad($this->localidad)
            ->setProvincia($this->provincia)->setTelefono($this->telefono)->setEmail($this->email)->setRegistroSanitario($this->registroSanitario);
    }
}
