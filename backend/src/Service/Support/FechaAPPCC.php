<?php

declare(strict_types=1);

namespace App\Service\Support;

/** Fechas externas estrictas, sin fechas relativas ni normalizaciones silenciosas. */
final class FechaAPPCC
{
    public static function parsear(string $valor, bool $permitirDia = true): \DateTimeImmutable
    {
        $formato = null;
        if ($permitirDia && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $valor) === 1) {
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
        throw new \InvalidArgumentException(sprintf('La fecha "%s" no es válida. Usa %sYYYY-MM-DDTHH:MM:SSZ/±HH:MM.', $valor, $permitirDia ? 'YYYY-MM-DD o ' : ''));
    }
}
