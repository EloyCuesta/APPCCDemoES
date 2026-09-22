# Entrega: plantillas iniciales y fotografía obligatoria

Trabajo sobre `main` local `de36172`, que conserva el commit de consultas del MVP por delante del `main` remoto `be2b6e5` comprobado durante la entrega. No se modifica el frontend ni ninguna migración publicada.

El [contrato HTTP y las decisiones de configuración](plantillas-iniciales.md) describen el catálogo, `POST /api/plantillas-appcc/{id}/aplicar`, los recibos idempotentes y el flujo de foto obligatoria. Se reutiliza `PlantillaAPPCCService`, incluido el onboarding interno.

## Archivos añadidos

- `backend/resources/plantillas/restaurante-v1.json`
- `backend/resources/plantillas/obrador-v1.json`
- `backend/resources/plantillas/catering-v1.json`
- `backend/src/Command/CargarPlantillasCommand.php`
- `backend/src/Entity/AplicacionPlantillaAPPCC.php`
- `backend/src/Dto/AplicarPlantillaInput.php`
- `backend/src/Dto/AplicarPlantillaOutput.php`
- `backend/src/State/Processor/AplicarPlantillaProcessor.php`
- `backend/migrations/Version20260922090000.php`
- `backend/tests/PlantillasApiTest.php`
- `backend/tests/ConcurrenciaPlantillasTest.php`
- `backend/tests/MigracionPlantillasTest.php`
- `backend/tests/NoConformidadesApiTest.php`
- `backend/docs/plantillas-iniciales.md`
- `backend/docs/entrega-plantillas-iniciales.md`

## Archivos modificados

- `backend/src/Entity/PlantillaAPPCC.php`: código de catálogo y operación API Platform.
- `backend/src/Entity/TareaAPPCC.php`: marca protegida y validación de configuración pendiente.
- `backend/src/Entity/ConfiguracionEstablecimiento.php`: fotografía obligatoria por defecto y rechazo de desactivación.
- `backend/src/Service/PlantillaAPPCCService.php`: carga reproducible, validación, aplicación transaccional y recibo idempotente.
- `backend/src/Security/TenantAuthorization.php`: revalidación de permisos después del bloqueo.
- `backend/src/Service/Support/ContextoAPPCC.php`: impedir usar un control pendiente de configuración.
- `backend/src/Service/RegistroAPPCCService.php`: fotografía independiente de opciones de configuración.
- `backend/tests/Support/PostgresTestCase.php`: fixture explícito de registro con subida real y preparación de ambas cachés de rutas antes de lanzar workers concurrentes en Windows.
- `backend/tests/BusinessLogicTest.php`, `backend/tests/SecurityTest.php`, `backend/tests/MembresiasTest.php`, `backend/tests/MigrationPlanTest.php`: los registros no conformes válidos usan fotografías reales, manteniendo los controles de rollback, autoría, aislamiento, historial y migración.
- `backend/tests/EvidenciasTest.php`: los casos que antes permitían foto opcional ahora comprueban la obligación incluso con configuración manipulada; se conservan los casos conformes.
- `backend/tests/EvidenciasApiTest.php`: comprobar obligación por defecto sin activarla en el fixture.
- `backend/tests/MvpModelTest.php`: repetir plantilla comprueba recibo y ausencia de duplicados/ejecuciones, según el nuevo contrato.
- `.github/workflows/backend-ci.yml`: carga del catálogo después de migrar y validar el esquema.
- `backend/docs/modelo-mvp.md`, `backend/docs/configuracion-saas.md`, `backend/docs/evidencias-registros.md`: actualización del esquema y de la regla fija de fotografía.

## Comprobaciones ejecutadas

Entorno real: PHP **8.3.11**, PostgreSQL **18.3**, PHPUnit **12.5.35**. Las pruebas usan exclusivamente `appcc_mvp_20260912_test`, con las protecciones de `PostgresSafety`; desarrollo usa `appcc_demo_es`.

- Migración incremental aplicada en test hasta `Version20260922090000`.
- Migraciones pendientes aplicadas en desarrollo: la publicada `Version20260916090000` y la nueva `Version20260922090000`.
- Esquema Doctrine validado en ambos entornos, sin diferencias.
- `lint:container --env=test`: correcto.
- `composer validate --strict --no-check-publish`: correcto.
- `php -l`: correctos los 194 archivos PHP de `src`, `tests` y `migrations`.
- `git diff --check`: correcto.
- Carga real del catálogo en desarrollo ejecutada dos veces: exactamente tres plantillas activas, mismos códigos e IDs en ambas ejecuciones.

La primera ejecución completa detectó una carrera de regeneración de la caché de rutas de Symfony en Windows en una prueba existente de concurrencia de evidencias. El proceso HTTP devolvía el error de token esperado, pero la serialización del error fallaba al renombrar simultáneamente el archivo de rutas. El soporte de pruebas prepara ahora tanto el matcher como el generador antes de lanzar workers; se mantienen las comprobaciones de bloqueo real, códigos HTTP, consumo único y persistencia.

## Para empezar el frontend

El backend entrega el catálogo, la aplicación con resumen y las tareas consultables. El frontend debe recoger límites propios, unidad e instrucciones antes de activar controles numéricos y mostrar las tareas pendientes de configuración. La respuesta de aplicación distingue recursos creados ahora del recibo original; después de editar se consultan las IRI para obtener el estado actual.

La carga `app:plantillas:cargar` debe formar parte de cada despliegue; ya se incluye en CI y se ha ejecutado localmente. Los horarios y frecuencias iniciales deben adaptarse a la actividad real. Para mostrar agenda deben materializarse las ejecuciones recurrentes con el comando operativo existente y programar las tareas bajo demanda por API. La fotografía debe subirse antes de enviar cualquier registro no conforme, conservando el token para esa operación.

No se implementa actualización automática de una plantilla aplicada a otra versión ni conciliación de aplicaciones antiguas sin recibo. Las colisiones de nombres requieren revisión y fallan sin cambios parciales. Los registros históricos sin foto se conservan; la obligación se aplica a todos los nuevos registros. Se mantiene la limitación existente de almacenamiento local privado con volumen persistente. La idoneidad sanitaria de límites y procedimientos corresponde a la configuración del establecimiento, no al catálogo inicial.
