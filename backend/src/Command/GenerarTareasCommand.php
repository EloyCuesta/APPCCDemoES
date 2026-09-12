<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GeneradorTareasProgramadasService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tareas:generar', description: 'Generar ejecuciones APPCC recurrentes.')]
final class GenerarTareasCommand extends Command
{
    public function __construct(
        private readonly GeneradorTareasProgramadasService $generador,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('desde', null, InputOption::VALUE_REQUIRED, 'Inicio de la ventana (YYYY-MM-DD o fecha ISO).')
            ->addOption('hasta', null, InputOption::VALUE_REQUIRED, 'Fin inclusivo de la ventana (YYYY-MM-DD o fecha ISO).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            [$desde, $hasta, $fechasCalendario] = $this->intervalo($input);
            $resultado = $this->generador->generar($desde, $hasta, $fechasCalendario);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (\Throwable $e) {
            $io->error('No se pudo completar la generación: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->title('Generación APPCC completada');
        $io->table(['Métrica', 'Total'], [
            ['Tareas analizadas', $resultado->tareasAnalizadas],
            ['Ocurrencias calculadas', $resultado->ocurrenciasCalculadas],
            ['Creadas', $resultado->creadas],
            ['Ya existentes', $resultado->existentes],
            ['Ignoradas', $resultado->ignoradas],
        ]);
        foreach ($resultado->ignoradasDetalle as $detalle) {
            $io->warning($detalle);
        }

        return Command::SUCCESS;
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable, bool} */
    private function intervalo(InputInterface $input): array
    {
        $desdeTexto = $input->getOption('desde');
        $hastaTexto = $input->getOption('hasta');
        $desde = $desdeTexto === null ? null : $this->parsear($desdeTexto);
        $hasta = $hastaTexto === null ? null : $this->parsear($hastaTexto);
        $esDia = static fn (?string $texto): bool => $texto !== null && strlen($texto) === 10;
        if ($desde !== null && $hasta !== null && $esDia($desdeTexto) !== $esDia($hastaTexto)) {
            throw new \InvalidArgumentException('Usa dos fechas YYYY-MM-DD o dos instantes ISO con zona horaria; no mezcles formatos.');
        }
        $fechasCalendario = $esDia($desdeTexto) || $esDia($hastaTexto);
        // Un único límite ancla también el valor por defecto del otro extremo.
        $desde ??= $hasta?->modify('-7 days') ?? $this->clock->now();
        $hasta ??= $desde->modify('+7 days');
        if ($hasta < $desde) {
            throw new \InvalidArgumentException('La opción --hasta debe ser posterior o igual a --desde.');
        }

        return [$desde, $hasta, $fechasCalendario];
    }

    private function parsear(string $valor): \DateTimeImmutable
    {
        $formato = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $valor) === 1) {
            $formato = 'Y-m-d';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $valor, $partes) === 1) {
            if (str_starts_with(ltrim($partes[1], '+-'), '14:') && substr($partes[1], -2) !== '00') {
                throw new \InvalidArgumentException('El desplazamiento horario no puede superar 14:00.');
            }
            $valor = str_ends_with($valor, 'Z') ? substr($valor, 0, -1).'+00:00' : $valor;
            $formato = 'Y-m-d\TH:i:sP';
        }
        if ($formato !== null) {
            $fecha = \DateTimeImmutable::createFromFormat('!'.$formato, $valor, new \DateTimeZone('UTC'));
            $errores = \DateTimeImmutable::getLastErrors();
            if ($fecha !== false && $fecha->format($formato) === $valor && (int) $fecha->format('Y') > 0
                && ($errores === false || ($errores['warning_count'] === 0 && $errores['error_count'] === 0))) {
                return $fecha;
            }
        }
        throw new \InvalidArgumentException(sprintf('La fecha "%s" no es válida. Usa YYYY-MM-DD o YYYY-MM-DDTHH:MM:SSZ/±HH:MM.', $valor));
    }
}
