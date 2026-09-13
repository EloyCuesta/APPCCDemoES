<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\Entity\Usuario;
use App\Exception\BusinessRuleException;
use App\Repository\UsuarioRepository;
use App\Service\Support\FechaAPPCC;

final readonly class ProgramacionInputResolver
{
    public function __construct(private UsuarioRepository $usuarios) {}

    public static function identificador(mixed $valor): int
    {
        $id = is_scalar($valor) ? filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) : false;
        if ($id === false) { throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Recurso no encontrado.'); }
        return $id;
    }

    public function usuario(?string $iri): ?Usuario
    {
        if ($iri === null) { return null; }
        if (!preg_match('~^/api/usuarios/([1-9][0-9]*)$~D', $iri, $parts)
            || ($id = filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]])) === false) {
            throw new BusinessRuleException('El usuario debe ser una IRI /api/usuarios/{id} válida o null.');
        }
        // La pertenencia y actividad se validan bajo bloqueo en el servicio de dominio.
        return $this->usuarios->find($id) ?? throw new BusinessRuleException('El usuario no existe o no está disponible.');
    }

    public function fecha(string $valor): \DateTimeImmutable
    {
        try { return FechaAPPCC::parsear($valor, false); }
        catch (\InvalidArgumentException $e) { throw new BusinessRuleException($e->getMessage()); }
    }
}
