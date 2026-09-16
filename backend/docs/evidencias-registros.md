# Registros, evidencias privadas y confirmación

El registro del control conserva LexikJWT, `X-Establecimiento-Id`, roles por establecimiento, reglas temporales, cálculo de conformidad e incidencia automática. La evidencia se sube primero y se incorpora al crear el registro. No existe `POST /api/evidencias`, ni PATCH/DELETE de registros o evidencias. El frontend queda fuera de esta entrega.

## Configuración y despliegue

```dotenv
APPCC_EVIDENCIAS_DIR=%kernel.project_dir%/var/appcc/evidencias
APPCC_EVIDENCIAS_MAX_BYTES=10485760
APPCC_EVIDENCIAS_TTL_SECONDS=3600
```

Se requiere PHP `ext-fileinfo`. El máximo inicial es 10 MiB por archivo y diez evidencias por registro. La caducidad inicial es una hora. Ajustar también `upload_max_filesize`, `post_max_size` y el límite del proxy/web: deben permitir el archivo más el multipart. Los rechazos de PHP/proxy pueden producirse antes de llegar al controlador.

La ruta configurada debe ser absoluta una vez resuelto `%kernel.project_dir%`, fuera de `public/`, sin componentes `..`, enlaces simbólicos ni junctions. El servicio crea directorios con permisos 0700 y archivos con 0600 en Unix; en Windows se deben configurar ACL equivalentes para la cuenta de servicio. No se debe servir esta carpeta con un alias web ni conceder escritura a otros usuarios del sistema.

```text
var/appcc/evidencias/
  temporal/<64 caracteres hexadecimales aleatorios>
  definitivo/<64 caracteres hexadecimales aleatorios>
```

`backend/var/` ya está excluido de Git. En producción es **obligatorio un volumen persistente**, montado directamente en la ruta configurada; ambas carpetas deben permanecer en el mismo sistema de archivos para el movimiento mediante `rename`. Respaldar base de datos y volumen de forma coordinada y verificar su restauración. Un directorio efímero de un contenedor pierde las evidencias al recrearlo.

Tipos admitidos: `foto` con JPEG (`image/jpeg`), PNG (`image/png`) o WebP (`image/webp`); `documento` con PDF (`application/pdf`). Se comprueba MIME real con `finfo`, coincidencia con el MIME declarado, tamaño y estructura de imagen. Se rechazan SVG, HTML, ejecutables, nombres con rutas y la subida de tipo `firma`. El nombre original saneado es solo metadato. Claves, tamaño y SHA-256 se calculan en el servidor. Esta validación no sustituye un antivirus ni garantiza que un PDF carezca de contenido activo; las descargas son adjuntos.

## 1. Subir un archivo

ADMIN, RESPONSABLE y TRABAJADOR pueden subir. AUDITOR puede consultar y descargar, pero no subir ni registrar. Todas las peticiones requieren JWT y un establecimiento con membresía activa.

```bash
curl -X POST "$BASE/api/evidencias/subidas" \
  -H "Authorization: Bearer $JWT" \
  -H 'X-Establecimiento-Id: 1' \
  -F 'tipo=foto' \
  -F 'archivo=@camara.png;type=image/png'
```

Respuesta 201:

```json
{
  "token": "<token opaco devuelto por el servidor>",
  "nombreOriginal": "camara.png",
  "mimeType": "image/png",
  "tamanoBytes": 245031,
  "expiresAt": "2026-09-16T12:00:00+00:00"
}
```

El token tiene 256 bits aleatorios; en PostgreSQL solo se guarda su SHA-256. Está ligado a usuario, establecimiento, tipo y caducidad. La subida todavía **no es una Evidencia**. La respuesta lleva `Cache-Control: private, no-store`. No registrar tokens en telemetría del cliente ni incluirlos en URLs.

## 2. Crear el registro

```http
POST /api/registros
Authorization: Bearer <JWT>
X-Establecimiento-Id: 1
Content-Type: application/ld+json
```

```json
{
  "tareaProgramada": "/api/tareas-programadas/15",
  "valorNumerico": "3.500",
  "observaciones": "Temperatura correcta.",
  "evidencias": [
    {"token": "<token recibido en la subida>", "tipo": "foto"}
  ],
  "confirmarRegistro": true
}
```

Devuelve 201. Se mantienen `datos` y `conforme` para los controles estructurados y `fechaHora` como fecha efectiva del control, sometida a las reglas temporales existentes. `createdAt` y la fecha de confirmación siempre proceden del servidor. Se rechazan campos extra: no enviar `establecimiento`, `usuario`, `subidaPor`, `confirmadoPor`, `confirmadoAt`, versión de declaración, claves, MIME, tamaño o hash. Dentro de `evidencias` solo se aceptan `token` y `tipo`.

Si `requiereFotoNoConforme=true`, un resultado no conforme exige una fotografía válida en esta misma operación; faltar la foto devuelve **422**. PDF, tokens caducados, consumidos, duplicados, de otro usuario/tenant o archivos alterados no cumplen el requisito. Conformidad calculada por el servidor evita eludirlo enviando `conforme=true` en un control numérico. Un resultado conforme no necesita foto; con la opción desactivada tampoco la necesita uno no conforme. No hay una operación posterior para completar un registro sin foto.

Si `requiereFirmaRegistro=true`, `confirmarRegistro` debe ser el booleano exacto `true`; omisión, `false`, `null`, cadenas o números devuelven 422. Con la opción desactivada se permite **confirmación voluntaria**, además de registrar sin confirmar. Se guarda el mismo usuario JWT que registra, el reloj del servidor y `versionDeclaracionFirma="1"`.

Texto correspondiente a la versión 1:

> Confirmo que los datos introducidos son correctos y corresponden al control realizado.

Es una confirmación interna auditable, **no una firma electrónica cualificada**. La firma no se puede añadir, cambiar ni retirar después. PostgreSQL exige fecha y firmante ambos nulos o ambos presentes, y que el firmante coincida con el autor. Triggers bloquean UPDATE/DELETE de registros y evidencias, incluidos cambios por SQL directo. No se añaden borrados en cascada ni `orphanRemoval`.

## 3. Consultar y descargar

`GET /api/evidencias` y `GET /api/evidencias/{id}` exponen metadatos, referencias y `downloadUrl`; nunca `storageKey` ni ruta física. La pertenencia sigue derivándose del único padre, registro o incidencia. Las evidencias históricas de incidencias mantienen esta relación; esta entrega crea nuevas evidencias junto al registro.

```bash
curl "$BASE/api/evidencias/7/descargar" \
  -H "Authorization: Bearer $JWT" \
  -H 'X-Establecimiento-Id: 1' \
  --output evidencia.png
```

La respuesta transmite un flujo sin cargar todo el archivo en memoria. Usa MIME comprobado, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store, max-age=0` y Vary por Authorization/establecimiento. La URL no concede acceso por sí sola; abrirla sin las cabeceras no descarga el archivo.

| Código | Caso |
|---|---|
| 401 | JWT ausente/no válido o cuenta no autorizada para autenticación |
| 400 | Cabecera tenant ausente/incorrecta, multipart inválido o campos JSON de auditoría |
| 403 | Falta membresía activa, establecimiento inactivo o rol sin permiso |
| 404 | Evidencia inexistente o perteneciente a otro establecimiento |
| 405 | POST de metadatos, PATCH o DELETE de históricos |
| 409 | Conflicto de unicidad/concurrencia |
| 422 | Regla de control, foto, firma, MIME/tamaño o token inválido |
| 503 | Archivo ausente/alterado, almacenamiento no disponible o persistencia con archivos fallida |

Una ausencia física conserva la fila histórica y registra la inconsistencia usando su identificador; no devuelve la ruta interna. Los metadatos heredados no se convierten en archivos inventados: si apuntan a claves antiguas o ausentes, el verificador los señala para revisión.

## Transacción y compensación

1. Se bloquea `TareaProgramada` con `FOR UPDATE` y se recarga. Se validan tenant, membresía, estado y reglas del control.
2. Se bloquean los tokens en orden estable; se verifican propietario, tenant, tipo, expiración, consumo y hash/tamaño de todos los archivos antes de mover ninguno.
3. Se prepara registro/confirmación, se mueven los temporales a claves definitivas aleatorias y se crean las evidencias. Se marca cada token consumido.
4. Se completa la tarea, se crea incidencia e historial cuando corresponda, y se hace un único flush/commit.
5. Si falla SQL o un movimiento, se revierte PostgreSQL y los archivos ya movidos se devuelven a temporal en orden inverso: desaparecen de definitivo y el token puede reintentarse. Si no se pueden restaurar, se intenta eliminar el definitivo y se registra el fallo para revisión. Nunca se elimina información histórica ya confirmada.

Un caso de uso con archivos exige ser la transacción exterior; se rechaza su ejecución dentro de otra transacción arbitraria porque no podría compensar un rollback posterior del llamador. Las operaciones existentes sin archivos conservan su comportamiento anidado.

No existe atomicidad distribuida entre disco y PostgreSQL. Una caída abrupta del proceso, fallo de disco o resultado incierto de COMMIT tras pérdida de conexión exige conciliación operativa. La compensación cubre excepciones observadas, no una terminación forzada de la máquina. No se debe reintentar ciegamente después de un error de conexión: consultar antes si la tarea ya quedó completada.

## Mantenimiento

```bash
php bin/console app:evidencias:limpiar-temporales
php bin/console app:evidencias:verificar
```

Ejecutar la limpieza periódicamente, por ejemplo cada quince minutos. Borra filas y archivos temporales caducados en lotes de 100; utiliza `FOR UPDATE SKIP LOCKED` para no interferir con un consumo en curso. Nunca borra definitivos. La operación es repetible; si la eliminación de disco falla, conserva la fila para reintento. Una caída entre la escritura inicial de un archivo y la inserción SQL puede dejar un temporal sin fila: requiere revisión manual del volumen.

El verificador comprueba filas sin archivo, archivos definitivos sin fila, tamaños y SHA-256. Devuelve 0 si no encuentra problemas y 1 si detecta inconsistencias; solo muestra IDs y recuentos. No borra archivos ni filas. Ejecutarlo en una ventana sin escrituras evita señalar como huérfano un archivo recién movido antes de su commit. Revisar también espacio disponible, cuotas, permisos y copias de seguridad.

## Migración y pruebas

`Version20260916090000` añade subidas temporales, índices, claves foráneas RESTRICT, confirmación nullable y restricciones/triggers. Los históricos mantienen confirmación nula; no se fabrican firmas ni archivos. El rollback de la migración se bloquea si hay subidas o confirmaciones que se perderían. No se han editado migraciones anteriores.

Las pruebas usan PostgreSQL real y directorios aleatorios bajo `var/evidencias-tests/`. Incluyen uploads reales de los cuatro formatos, HTTP/JWT/roles, firmas y fotos, manipulación, inmutabilidad SQL, fallos de persistencia/movimiento, limpieza, verificación y procesos concurrentes que intentan consumir el mismo token. Se adapta el contrato antiguo de las pruebas de seguridad: se conserva su comprobación de autoría/aislamiento y se añade el rechazo del antiguo POST JSON y de campos de auditoría. Las pruebas históricas de migración siguen verificando backfill y reversión.

Limitaciones: almacenamiento de un solo servidor/volumen, sin replicación ni backend S3/R2/MinIO, sin cuotas por tenant todavía, sin antivirus externo, OCR, firma manuscrita, firma cualificada ni frontend. Para varias instancias, todas necesitan acceso coherente al mismo volumen; esta entrega no configura almacenamiento compartido ni alta disponibilidad.
