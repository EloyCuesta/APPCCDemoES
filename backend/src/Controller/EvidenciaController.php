<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Evidencia;
use App\Enum\TipoEvidencia;
use App\Exception\{BusinessRuleException, EvidenciaStorageException};
use App\Repository\EvidenciaRepository;
use App\Security\{CurrentEstablecimientoContext, TenantAuthorization};
use App\Service\Storage\EvidenciaStorageInterface;
use App\Service\SubidaEvidenciaService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, ResponseHeaderBag, StreamedResponse};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, NotFoundHttpException};
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class EvidenciaController
{
    public function __construct(private CurrentEstablecimientoContext $current, private TenantAuthorization $authorization,
        private SubidaEvidenciaService $subidas, private EvidenciaRepository $evidencias, private EvidenciaStorageInterface $storage,
        private LoggerInterface $logger) {}

    #[Route('/api/evidencias/subidas', name: 'appcc_evidencias_subidas', methods: ['POST'])]
    public function subir(Request $request): JsonResponse
    {
        $local = $this->current->establecimiento();
        $usuario = $this->current->usuario();
        $this->authorization->assertOperarRegistros();
        if (!str_starts_with($request->headers->get('Content-Type', ''), 'multipart/form-data')
            || array_diff(array_keys($request->request->all()), ['tipo']) !== []
            || array_keys($request->files->all()) !== ['archivo']) {
            throw new BadRequestHttpException('Se requiere multipart/form-data con archivo y tipo.');
        }
        $archivo = $request->files->get('archivo');
        $tipo = $request->request->all()['tipo'] ?? null;
        if (!$archivo instanceof UploadedFile || !in_array($tipo, ['foto', 'documento'], true)) {
            throw new BadRequestHttpException('Debe indicar un archivo y tipo foto o documento.');
        }
        try { return new JsonResponse($this->subidas->subir($archivo, TipoEvidencia::from($tipo), $usuario, $local), 201, ['Cache-Control' => 'private, no-store']); }
        catch (BusinessRuleException $e) { return new JsonResponse(['detail' => $e->getMessage()], 422, ['Cache-Control' => 'private, no-store']); }
        catch (EvidenciaStorageException $e) { $this->logger->error('Fallo del almacenamiento durante una subida.'); throw $e; }
    }

    #[Route('/api/evidencias/{id}/descargar', name: 'appcc_evidencias_descargar', requirements: ['id' => '[1-9][0-9]*'], methods: ['GET'])]
    public function descargar(string $id): StreamedResponse
    {
        $this->authorization->assertReadClass(Evidencia::class);
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false) {
            throw new NotFoundHttpException('Evidencia no encontrada.');
        }
        $evidencia = $this->evidencias->find($id) ?? throw new NotFoundHttpException('Evidencia no encontrada.');
        $this->authorization->assertResource($evidencia);
        try { $flujo = $this->storage->abrir($evidencia->getStorageKey()); }
        catch (EvidenciaStorageException $e) {
            $this->logger->error('Inconsistencia: archivo de evidencia no disponible.', ['evidencia_id' => $evidencia->getId()]);
            throw $e;
        }
        $response = new StreamedResponse(static function () use ($flujo): void {
            try { fpassthru($flujo); } finally { fclose($flujo); }
        });
        $response->headers->set('Content-Type', $evidencia->getMimeType());
        $response->headers->set('Content-Length', (string) $evidencia->getTamanoBytes());
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $evidencia->getNombreOriginal(), 'evidencia'));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->setVary(['Authorization', 'X-Establecimiento-Id']);
        return $response;
    }
}
