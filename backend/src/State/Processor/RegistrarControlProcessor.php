<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\RegistroAPPCC;
use App\Dto\CrearRegistroInput;
use App\Entity\TareaProgramada;
use App\Security\{CurrentEstablecimientoContext, TenantAuthorization};
use Doctrine\ORM\EntityManagerInterface;
use App\Service\RegistroAPPCCService;
use Psr\Clock\ClockInterface;

/** @implements ProcessorInterface<CrearRegistroInput, RegistroAPPCC> */
final readonly class RegistrarControlProcessor implements ProcessorInterface
{
    public function __construct(private RegistroAPPCCService $service, private ClockInterface $clock,
        private CurrentEstablecimientoContext $current, private TenantAuthorization $authorization, private EntityManagerInterface $em)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RegistroAPPCC
    {
        if (!$data instanceof CrearRegistroInput) {
            throw new \InvalidArgumentException('El recurso recibido no es válido.');
        }
        $local = $this->current->establecimiento();
        $this->authorization->assertOperarRegistros();
        if (!preg_match('~^/api/tareas-programadas/([1-9][0-9]*)$~D', $data->tareaProgramada, $parts)) {
            throw new \App\Exception\BusinessRuleException('Debe indicar una tarea programada válida.');
        }
        $id = ProgramacionInputResolver::identificador($parts[1]);
        $programada = $this->em->getRepository(TareaProgramada::class)->findOneBy(['id' => $id, 'establecimiento' => $local])
            ?? throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Tarea programada no encontrada.');
        try { $fecha = $data->fechaHora === null ? $this->clock->now() : \App\Service\Support\FechaAPPCC::parsear($data->fechaHora, false); }
        catch (\InvalidArgumentException $e) { throw new \App\Exception\BusinessRuleException($e->getMessage()); }
        $registro = (new RegistroAPPCC())->setTareaProgramada($programada)->setEstablecimiento($local)->setUsuario($this->current->usuario())
            ->setFechaHora($fecha)->setValorNumerico($data->valorNumerico)->setDatos($data->datos)->setConforme($data->conforme)->setObservaciones($data->observaciones);
        return $this->service->registrar($registro, $data->evidencias, $data->confirmarRegistro);
    }
}
