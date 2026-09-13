<?php
declare(strict_types=1);
namespace App\Dto;

use App\Entity\EntidadFiscal;
use App\Enum\TipoEntidadFiscal;

/** Lista cerrada; las validaciones de empresa/autónomo se ejecutan sobre la entidad. */
final class EntidadFiscalInput
{
    public ?TipoEntidadFiscal $tipo = null;
    public string $nif = '';
    public ?string $razonSocial = null;
    public ?string $nombre = null;
    public ?string $apellidos = null;
    public ?string $nombreComercial = null;
    public string $direccion = '';
    public string $codigoPostal = '';
    public string $localidad = '';
    public string $provincia = '';
    public ?string $telefono = null;
    public ?string $email = null;

    public function crear(): EntidadFiscal
    {
        return (new EntidadFiscal())->setTipo($this->tipo)->setNif($this->nif)->setRazonSocial($this->razonSocial)
            ->setNombre($this->nombre)->setApellidos($this->apellidos)->setNombreComercial($this->nombreComercial)
            ->setDireccion($this->direccion)->setCodigoPostal($this->codigoPostal)->setLocalidad($this->localidad)
            ->setProvincia($this->provincia)->setTelefono($this->telefono)->setEmail($this->email);
    }
}
