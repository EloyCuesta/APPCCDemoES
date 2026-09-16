# Entrega de registros y evidencias

Base: `origin/main` verificado mediante fetch el 16 de septiembre de 2026, commit `00c94cd4f051f3130e812925ab45689481ad9e24`. Cambios locales sobre esa versión, sin publicación remota.

## Resultado

Almacenamiento privado local, subidas temporales con token de consumo único, fotografías obligatorias según configuración, confirmación interna del usuario JWT y descarga con autorización por establecimiento. Se preservan las reglas del control, las relaciones y las incidencias automáticas. La inmutabilidad se refuerza con triggers PostgreSQL.

La confirmación guarda autor, reloj del servidor y versión 1 de la declaración. No es firma electrónica cualificada. No se ha implementado frontend, firma manuscrita, OCR, antivirus externo ni almacenamiento S3/R2/MinIO.

## Archivos creados

Rutas relativas a `backend/`:

```text
migrations/Version20260916090000.php
src/Command/LimpiarTemporalesEvidenciaCommand.php
src/Command/VerificarEvidenciasCommand.php
src/Controller/EvidenciaController.php
src/Dto/ArchivoEvidencia.php
src/Dto/CrearRegistroInput.php
src/Entity/SubidaTemporalEvidencia.php
src/Exception/EvidenciaStorageException.php
src/Service/Storage/EvidenciaStorageInterface.php
src/Service/Storage/LocalEvidenciaStorage.php
src/Service/SubidaEvidenciaService.php
tests/ConcurrenciaEvidenciasTest.php
tests/EvidenciasApiTest.php
tests/EvidenciasTest.php
tests/Support/EvidenciaFixtures.php
tests/Fixtures/evidencia.jpg
tests/Fixtures/evidencia.png
tests/Fixtures/evidencia.webp
tests/Fixtures/evidencia.pdf
docs/evidencias-registros.md
docs/entrega-evidencias-registros.md
```

## Archivos modificados o sustituidos

```text
.github/workflows/backend-ci.yml
backend/.env
backend/composer.json
backend/composer.lock
backend/src/Entity/Evidencia.php
backend/src/Entity/RegistroAPPCC.php
backend/src/Security/TenantAuthorization.php
backend/src/Service/RegistroAPPCCService.php
backend/src/State/Processor/RegistrarControlProcessor.php
backend/tests/MigrationPlanTest.php
backend/tests/ProgramacionesApiTest.php
backend/tests/SecurityTest.php
backend/tests/Support/PostgresTestCase.php
backend/docs/modelo-mvp.md
backend/docs/configuracion-saas.md
backend/docs/ciclo-operativo-tareas.md
```

Se eliminan `backend/src/Service/EvidenciaService.php` y `backend/src/State/Processor/EvidenciaProcessor.php`: exponían la creación de metadatos sin archivo. Su función queda sustituida por subida temporal y consumo dentro del registro. No se elimina ninguna prueba. Composer solo declara `ext-fileinfo` y actualiza su lock; no cambia versiones de paquetes.

## Migración

Únicamente se añade `Version20260916090000`: entidad temporal, índices, FK RESTRICT, fecha/autor/versionado de confirmación, CHECK de pareja fecha-firmante y autor coincidente, validación SQL de subidas y triggers que bloquean UPDATE/DELETE de históricos. Las nueve migraciones anteriores permanecen intactas. Los registros existentes mantienen confirmación nula.

## Endpoints y ejemplos

Todas las operaciones requieren JWT y `X-Establecimiento-Id`. Los ejemplos completos de curl, respuestas, errores y declaración de firma están en [evidencias-registros.md](evidencias-registros.md).

| Operación | Entrada |
|---|---|
| `POST /api/evidencias/subidas` | multipart `archivo` + `tipo=foto` o `documento`; devuelve token |
| `POST /api/registros` | Datos del control, lista de tokens y `confirmarRegistro` |
| `GET /api/evidencias/{id}/descargar` | JWT y establecimiento; devuelve flujo adjunto privado |
| `GET /api/evidencias` y `GET /api/evidencias/{id}` | Metadatos y `downloadUrl`, sin clave interna |

Ejemplo de registro:

```json
{
  "tareaProgramada": "/api/tareas-programadas/15",
  "valorNumerico": "3.500",
  "observaciones": "Temperatura correcta.",
  "evidencias": [{"token": "<token recibido>", "tipo": "foto"}],
  "confirmarRegistro": true
}
```

`POST /api/evidencias` responde 405. No hay PATCH ni DELETE públicos de registros/evidencias.

## Compensación

La tarea y los tokens se bloquean dentro de una transacción. Se validan todos los archivos antes de moverlos y crear las evidencias. El commit incluye registro, firma, evidencias, consumo de tokens, estado de tarea e incidencia/historial.

Un error de SQL o movimiento revierte la base y restaura en temporal los archivos ya movidos, retirándolos de definitivo. Si la restauración falla, se intenta eliminar el definitivo y se informa para revisión. Un rollback correcto permite reutilizar el token hasta su expiración. Las pruebas fuerzan un fallo SQL tras mover dos archivos y otro en el segundo movimiento; comprueban que no quedan registros/evidencias ni archivos definitivos.

## Validación

Se ejecuta sobre PHP 8.3.11 y PostgreSQL 18.3, en la base de pruebas protegida por `PostgresSafety`, con un directorio aislado por prueba. La batería inicial fue `OK (261 tests, 1211 assertions)`. No se han borrado, saltado ni relajado pruebas o restricciones para ocultar errores. Las pruebas del contrato retirado se adaptan al rechazo explícito y al flujo real, conservando las comprobaciones de autoría, roles y aislamiento.

Los cinco comandos solicitados se ejecutan con `APP_ENV=test`. El script local del repositorio activa sodium para el proceso actual; no se modifica php.ini global.

| Comando | Resultado final |
|---|---|
| `composer validate --strict` | Código 0; `./composer.json is valid` |
| `php bin/console lint:container` | Código 0; servicios y tipos correctos |
| `php bin/console doctrine:migrations:migrate --no-interaction` | Código 0; versión `DoctrineMigrations\Version20260916090000` aplicada y al día |
| `php bin/console doctrine:schema:validate` | Código 0; mapping correcto y esquema sincronizado |
| `php bin/phpunit` | Código 0; 348 pruebas, 1451 aserciones, sin errores, avisos ni pruebas saltadas |

Salida final de PHPUnit:

```text
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.3.11
Configuration: D:\PROYECTOS\APPCCDemoES\backend\phpunit.dist.xml

Time: 02:27.032, Memory: 72.00 MB

OK (348 tests, 1451 assertions)
```

Son 87 casos adicionales a los 261 originales. El log completo queda en `backend/var/evidencias-phpunit-final.log` (ignorado por Git). La ejecución es local en Windows con PostgreSQL real; el workflow Linux se actualiza con `fileinfo`, pero no se ha disparado una ejecución remota ni se ha hecho push.

## Riesgos y operación pendientes

- Aprovisionar volumen persistente privado, permisos/ACL y copias coordinadas de SQL y archivos; ajustar límites PHP/proxy.
- Instalar la limpieza periódica de temporales y ejecutar el verificador de integridad en ventanas sin escrituras.
- Disco y SQL no comparten transacción: una caída abrupta o un COMMIT de resultado incierto requiere conciliación, no reintento ciego. Los temporales sin fila tras una caída inicial necesitan revisión manual.
- Revisar los metadatos heredados cuyo archivo físico no existe o cuya clave no pertenece al formato seguro; se conservan y se informa su inconsistencia.
- Sin cuotas por tenant, antivirus, replicación ni almacenamiento de objetos; el almacenamiento local necesita planificación de capacidad y disponibilidad.
