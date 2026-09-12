<?php

declare(strict_types=1);

namespace App\Service\Support;

use App\Entity\ConfiguracionEstablecimiento;
use App\Entity\Establecimiento;
use App\Entity\TareaAPPCC;
use App\Enum\FrecuenciaTarea;
use App\Exception\BusinessRuleException;
use App\Repository\ConfiguracionEntidadFiscalRepository;
use Psr\Clock\ClockInterface;

final readonly class CalendarioAPPCC
{
    public function __construct(private ClockInterface $clock, private ConfiguracionEntidadFiscalRepository $configuraciones)
    {
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable}|null Intervalo [inicio, fin) en la zona usada por Doctrine. */
    public function periodo(TareaAPPCC $tarea, Establecimiento $local, ?\DateTimeImmutable $fecha = null): ?array
    {
        $config = $local->getEntidadFiscal() === null ? null : $this->configuraciones->findOneBy(['entidadFiscal' => $local->getEntidadFiscal()]);
        $fecha = ($fecha ?? $this->clock->now())->setTimezone(new \DateTimeZone($config?->getZonaHoraria() ?? 'Europe/Madrid'));
        $inicio = match ($tarea->getFrecuencia()) {
            FrecuenciaTarea::DIARIA => $fecha->setTime(0, 0),
            FrecuenciaTarea::SEMANAL => $fecha->modify('monday this week')->setTime(0, 0),
            FrecuenciaTarea::MENSUAL => $fecha->modify('first day of this month')->setTime(0, 0),
            default => null,
        };
        if ($inicio === null) {
            return null; // Sin calendario de turnos ni eventos de recepción.
        }
        $fin = $inicio->modify(match ($tarea->getFrecuencia()) {
            FrecuenciaTarea::DIARIA => '+1 day',
            FrecuenciaTarea::SEMANAL => '+1 week',
            default => '+1 month',
        });

        return [$this->paraPersistir($inicio), $this->paraPersistir($fin)];
    }

    public function validarFecha(TareaAPPCC $tarea, Establecimiento $local, ConfiguracionEstablecimiento $config, \DateTimeImmutable $fecha): void
    {
        $ahora = $this->clock->now();
        if ($fecha->getTimestamp() > $ahora->getTimestamp()) {
            throw new BusinessRuleException('La fecha del registro no puede estar en el futuro.');
        }
        $periodo = $this->periodo($tarea, $local, $ahora);
        if ($periodo === null || $fecha >= $periodo[0]) {
            return;
        }
        if (!$config->isPermiteRegistrosAtrasados()) {
            throw new BusinessRuleException('El establecimiento no permite registros de períodos anteriores.');
        }
        $maximo = $config->getMaximoMinutosRegistroAtrasado();
        if ($maximo !== null && $ahora->getTimestamp() - $fecha->getTimestamp() > $maximo * 60) {
            throw new BusinessRuleException('El registro supera el máximo de minutos de retraso permitido.');
        }
    }

    public function paraPersistir(\DateTimeImmutable $fecha): \DateTimeImmutable
    {
        return $fecha->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
