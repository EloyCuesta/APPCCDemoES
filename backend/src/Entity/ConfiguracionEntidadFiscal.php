<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\ConfiguracionEntidadFiscalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ConfiguracionEntidadFiscalRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['entidadFiscal'], message: 'La entidad fiscal ya tiene una configuración.')]
#[ApiResource(operations: [
    new GetCollection(uriTemplate: '/configuraciones-entidad-fiscal'),
    new Get(uriTemplate: '/configuraciones-entidad-fiscal/{id}'),
    new Patch(uriTemplate: '/configuraciones-entidad-fiscal/{id}'),
])]
class ConfiguracionEntidadFiscal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'configuracion')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull(message: 'Debe indicar la entidad fiscal.')]
    private ?EntidadFiscal $entidadFiscal = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'El campo ruta del logo no puede superar {{ limit }} caracteres.')]
    private ?string $logoPath = null;

    #[ORM\Column(length: 10, options: ['default' => 'es'])]
    #[Assert\NotBlank(message: 'El campo idioma es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 10, maxMessage: 'El campo idioma no puede superar {{ limit }} caracteres.')]
    private string $idioma = 'es';

    #[ORM\Column(length: 100, options: ['default' => 'Europe/Madrid'])]
    #[Assert\NotBlank(message: 'El campo zona horaria es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 100, maxMessage: 'El campo zona horaria no puede superar {{ limit }} caracteres.')]
    #[Assert\Timezone(message: 'La zona horaria no es válida.')]
    private string $zonaHoraria = 'Europe/Madrid';

    #[ORM\Column(options: ['default' => true])]
    private bool $notificacionesEmail = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $notificarIncidencias = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $notificarTareasPendientes = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $resumenDiarioEmail = false;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Los días de conservación deben ser mayores que cero.')]
    #[ApiProperty(description: 'Preferencia de conservación; no provoca la eliminación automática de registros.')]
    private ?int $diasConservacionRegistros = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $permitirGestionMultiEstablecimiento = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntidadFiscal(): ?EntidadFiscal
    {
        return $this->entidadFiscal;
    }

    public function setEntidadFiscal(?EntidadFiscal $entidadFiscal): static
    {
        if ($this->entidadFiscal === $entidadFiscal) {
            return $this;
        }

        $previous = $this->entidadFiscal;
        $this->entidadFiscal = $entidadFiscal;

        if ($previous !== null && $previous->getConfiguracion() === $this) {
            $previous->setConfiguracion(null);
        }

        if ($entidadFiscal !== null && $entidadFiscal->getConfiguracion() !== $this) {
            $entidadFiscal->setConfiguracion($this);
        }

        return $this;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;

        return $this;
    }

    public function getIdioma(): string
    {
        return $this->idioma;
    }

    public function setIdioma(string $idioma): static
    {
        $this->idioma = $idioma;

        return $this;
    }

    public function getZonaHoraria(): string
    {
        return $this->zonaHoraria;
    }

    public function setZonaHoraria(string $zonaHoraria): static
    {
        $this->zonaHoraria = $zonaHoraria;

        return $this;
    }

    public function isNotificacionesEmail(): bool
    {
        return $this->notificacionesEmail;
    }

    public function setNotificacionesEmail(bool $notificacionesEmail): static
    {
        $this->notificacionesEmail = $notificacionesEmail;

        return $this;
    }

    public function isNotificarIncidencias(): bool
    {
        return $this->notificarIncidencias;
    }

    public function setNotificarIncidencias(bool $notificarIncidencias): static
    {
        $this->notificarIncidencias = $notificarIncidencias;

        return $this;
    }

    public function isNotificarTareasPendientes(): bool
    {
        return $this->notificarTareasPendientes;
    }

    public function setNotificarTareasPendientes(bool $notificarTareasPendientes): static
    {
        $this->notificarTareasPendientes = $notificarTareasPendientes;

        return $this;
    }

    public function isResumenDiarioEmail(): bool
    {
        return $this->resumenDiarioEmail;
    }

    public function setResumenDiarioEmail(bool $resumenDiarioEmail): static
    {
        $this->resumenDiarioEmail = $resumenDiarioEmail;

        return $this;
    }

    public function getDiasConservacionRegistros(): ?int
    {
        return $this->diasConservacionRegistros;
    }

    public function setDiasConservacionRegistros(?int $diasConservacionRegistros): static
    {
        $this->diasConservacionRegistros = $diasConservacionRegistros;

        return $this;
    }

    public function isPermitirGestionMultiEstablecimiento(): bool
    {
        return $this->permitirGestionMultiEstablecimiento;
    }

    public function setPermitirGestionMultiEstablecimiento(bool $permitirGestionMultiEstablecimiento): static
    {
        $this->permitirGestionMultiEstablecimiento = $permitirGestionMultiEstablecimiento;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
