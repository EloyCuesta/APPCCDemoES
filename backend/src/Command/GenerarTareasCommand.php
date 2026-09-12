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
        $ahora = $this->clock->now();
        $desde = $this->parsear($input->getOption('desde'), $ahora, false);
        $hasta = $this->parsear($input->getOption('hasta'), $ahora->modify('+7 days'), true);
        if ($hasta->getTimestamp() < $desde->getTimestamp()) {
            throw new \InvalidArgumentException('La opción --hasta debe ser posterior o igual a --desde.');
        }

        $desdeTexto = $input->getOption('desde');
        $hastaTexto = $input->getOption('hasta');
        $fechasCalendario = is_string($desdeTexto) && is_string($hastaTexto)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $desdeTexto) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $hastaTexto) === 1;

        return [$desde, $hasta, $fechasCalendario];
    }

    private function parsear(?string $valor, \DateTimeImmutable $defecto, bool $finDelDia): \DateTimeImmutable
    {
        if ($valor === null) {
            return $defecto;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $valor) === 1) {
            try {
                return new \DateTimeImmutable($valor.' '.($finDelDia ? '23:59:59' : '00:00:00'), new \DateTimeZone('UTC'));
            } catch (\Exception) {
                throw new \InvalidArgumentException(sprintf('La fecha "%s" no es válida.', $valor));
            }
        }
        try {
            return new \DateTimeImmutable($valor);
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf('La fecha "%s" no es válida.', $valor));
        }
    }
}
