<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{Establecimiento, SubidaTemporalEvidencia, Usuario};
use App\Enum\TipoEvidencia;
use App\Exception\BusinessRuleException;
use App\Service\Storage\EvidenciaStorageInterface;
use App\Service\Support\{ContextoAPPCC, TransaccionAPPCC};
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class SubidaEvidenciaService
{
    public function __construct(
        private EntityManagerInterface $em,
        private EvidenciaStorageInterface $storage,
        private ContextoAPPCC $contexto,
        private TransaccionAPPCC $transaccion,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire('%env(int:APPCC_EVIDENCIAS_TTL_SECONDS)%')] private int $ttl,
    ) {}

    /** @return array{token: string, nombreOriginal: string, mimeType: string, tamanoBytes: int, expiresAt: string} */
    public function subir(UploadedFile $archivo, TipoEvidencia $tipo, Usuario $usuario, Establecimiento $local): array
    {
        $local = $this->contexto->establecimiento($local);
        $usuario = $this->contexto->usuario($usuario, $local);
        if ($this->em->getConnection()->isTransactionActive() || $this->ttl < 1) {
            throw new \LogicException('La subida requiere su propia transacción y una caducidad positiva.');
        }
        $guardado = $this->storage->guardarTemporal($archivo, $tipo);
        $token = bin2hex(random_bytes(32));
        $expires = $this->clock->now()->modify('+'.$this->ttl.' seconds');
        try {
            $this->transaccion->ejecutar(function () use ($token, $usuario, $local, $tipo, $guardado, $expires): void {
                $this->em->persist(new SubidaTemporalEvidencia(hash('sha256', $token), $usuario, $local, $tipo, $guardado, $expires));
            });
        } catch (\Throwable $e) {
            try { $this->storage->eliminar($guardado->storageKey); }
            catch (\Throwable) { $this->logger->error('No se pudo compensar una subida temporal.'); }
            $this->logger->error('No se pudo persistir una subida temporal.', ['error_type' => $e::class]);
            throw new \App\Exception\EvidenciaStorageException();
        }
        return ['token' => $token, 'nombreOriginal' => $guardado->nombreOriginal, 'mimeType' => $guardado->mimeType,
            'tamanoBytes' => $guardado->tamanoBytes, 'expiresAt' => $expires->format(DATE_ATOM)];
    }

    /** Validación y bloqueo antes de mover ningún archivo. @return list<SubidaTemporalEvidencia> */
    public function bloquear(array $entradas, Usuario $usuario, Establecimiento $local): array
    {
        if (!$this->em->getConnection()->isTransactionActive()) { throw new \LogicException('Se requiere una transacción.'); }
        if (!array_is_list($entradas) || count($entradas) > 10) { throw new BusinessRuleException('Se admiten hasta 10 evidencias por registro.'); }
        $tokens = [];
        foreach ($entradas as $entrada) {
            if (!is_array($entrada) || count($entrada) !== 2 || !is_string($entrada['token'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $entrada['token']) || !in_array($entrada['tipo'] ?? null, ['foto', 'documento'], true)) {
                throw new BusinessRuleException('Cada evidencia debe contener únicamente un token y su tipo (foto o documento).');
            }
            $hash = hash('sha256', $entrada['token']);
            if (isset($tokens[$hash])) { throw new BusinessRuleException('No se puede repetir un token de subida.'); }
            $tokens[$hash] = $entrada['tipo'];
        }
        ksort($tokens); // Orden estable para evitar interbloqueos entre varias subidas.
        $subidas = [];
        foreach ($tokens as $hash => $tipo) {
            $id = $this->em->getConnection()->fetchOne('SELECT id FROM subida_temporal_evidencia WHERE token_hash = ? AND usuario_id = ? AND establecimiento_id = ? FOR UPDATE', [$hash, $usuario->getId(), $local->getId()]);
            $subida = $id === false ? null : $this->em->find(SubidaTemporalEvidencia::class, $id);
            if ($subida !== null) { $this->em->refresh($subida); }
            if ($subida === null || $subida->getConsumidaAt() !== null || $subida->getExpiresAt() <= $this->clock->now() || $subida->getTipo()->value !== $tipo) {
                throw new BusinessRuleException('La subida temporal no está disponible para este usuario y establecimiento, ha caducado o ya se consumió.');
            }
            if (!$this->storage->verificar($subida->getStorageKey(), $subida->getTamanoBytes(), $subida->getHashSha256())) {
                $this->logger->error('Integridad incorrecta en una subida temporal.', ['subida_id' => $id]);
                throw new \App\Exception\EvidenciaStorageException();
            }
            $subidas[] = $subida;
        }
        return $subidas;
    }

    public function limpiarCaducadas(): int
    {
        $db = $this->em->getConnection();
        $total = 0;
        // Bloqueos compartidos con el consumo: nunca borrar una subida en uso.
        do {
            $cantidad = $db->transactional(function () use ($db): int {
                $filas = $db->fetchAllAssociative('SELECT id, storage_key FROM subida_temporal_evidencia WHERE expires_at <= ? ORDER BY id LIMIT 100 FOR UPDATE SKIP LOCKED', [$this->clock->now()], [\Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE]);
                foreach ($filas as $fila) {
                    $this->storage->eliminar($fila['storage_key']);
                    $db->delete('subida_temporal_evidencia', ['id' => $fila['id']]);
                }
                return count($filas);
            });
            $total += $cantidad;
        } while ($cantidad === 100);
        return $total;
    }
}
