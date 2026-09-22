# Plantillas iniciales y fotografía obligatoria

La API permite preparar un establecimiento ya creado, con JWT y membresía activa **ADMIN o RESPONSABLE**. No se incluye frontend.

## Instalación reproducible

Desde `backend`, con el entorno de destino configurado:

```console
php bin/console cache:clear
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:schema:validate
php bin/console app:plantillas:cargar
```

`app:plantillas:cargar` carga los JSON versionados de `resources/plantillas/` a través de `PlantillaAPPCCService`. Un código único (`restaurante-v1`, `obrador-v1`, `catering-v1`) identifica cada plantilla. Repetir el comando conserva IDs, contenido y estado de activación existentes; dos cargas concurrentes se serializan mediante un bloqueo transaccional PostgreSQL. La carga es un paso explícito de despliegue, separado de las migraciones de esquema. No crea establecimientos ni aplica plantillas por sí sola.

La limpieza de caché renueva también los metadatos de API Platform y las nuevas propiedades de lectura de las tareas. En producción, ejecutar estos comandos con `APP_ENV=prod` y la configuración correspondiente.

| Actividad | Planes | Puntos | Tareas | Controles numéricos pendientes |
|---|---:|---:|---:|---|
| Restaurante | 6 | 4 | 7 | Conservación en frío y cocción |
| Obrador | 6 | 4 | 7 | Conservación en frío y enfriamiento |
| Catering | 6 | 4 | 8 | Conservación, salida de transporte y servicio |

Cada plantilla contiene temperaturas, limpieza, recepción, alérgenos, trazabilidad y mantenimiento. Los puntos identifican equipos y zonas que el establecimiento debe adaptar. Los controles de recepción y trazabilidad tienen campos estructurados y evaluación manual explícita. Limpieza, alérgenos y mantenimiento usan respuesta booleana.

## Consultar y aplicar

```http
GET /api/plantillas-appcc HTTP/1.1
Authorization: Bearer <JWT>
Accept: application/ld+json
```

El catálogo es global y requiere autenticación. Permite consultar `codigo`, `tipoActividad`, `activa`, descripción y configuración; el frontend debe seleccionar una plantilla activa compatible. Los IDs se obtienen del catálogo o del comando, nunca se presuponen.

```http
POST /api/plantillas-appcc/2/aplicar HTTP/1.1
Authorization: Bearer <JWT>
X-Establecimiento-Id: 12
Content-Type: application/ld+json
Accept: application/ld+json

{}
```

El establecimiento se obtiene **exclusivamente de la cabecera**. El cuerpo es un objeto vacío; campos como `establecimiento`, `establecimientoId`, `configuracion` o `usuario` son rechazados (400). No se aceptan planes o tareas enviados por el cliente en esta operación. El ejemplo presupone que la plantilla 2 corresponde a la actividad del establecimiento 12.

Respuesta **200**, con campos funcionales como estos (se omite el envoltorio JSON-LD y se abrevia `resultadoInicial`):

```json
{
  "aplicacionId": 8,
  "plantillaId": 2,
  "establecimientoId": 12,
  "yaAplicada": false,
  "aplicadaAt": "2026-09-22T10:00:00+00:00",
  "creados": {"planes": 6, "puntos": 4, "tareas": 7},
  "resultadoInicial": {
    "planes": [{"id": 31, "iri": "/api/planes-control/31", "nombre": "Inicial · Control de temperaturas"}],
    "puntos": [{"id": 41, "iri": "/api/puntos-control/41", "nombre": "Inicial · Equipo de conservación en frío"}],
    "tareas": [{
      "id": 51,
      "iri": "/api/tareas/51",
      "nombre": "Revisar temperatura de conservación",
      "activa": false,
      "configuracionPendiente": true,
      "planControl": "/api/planes-control/31",
      "puntoControl": "/api/puntos-control/41"
    }]
  }
}
```

La respuesta real incluye **todos** los planes, puntos y tareas. Una repetición devuelve el mismo `aplicacionId`, fecha y `resultadoInicial`, `yaAplicada: true` y los tres contadores `creados` a cero. El recibo describe la creación original: para mostrar el estado actual tras una edición se deben consultar las IRI o las colecciones existentes. No se sobrescriben nombres, límites ni tareas adaptadas al repetir la aplicación.

Se bloquea la fila del establecimiento y se revalida la membresía después de esperar el bloqueo; plantilla, definiciones y recibo se procesan en una única transacción. Un índice UNIQUE sobre plantilla/establecimiento respalda la exclusión concurrente. El servicio sigue funcionando dentro de la transacción de onboarding. Un fallo, incluso al persistir el recibo después de obtener los IDs, revierte todas las definiciones.

| Código | Significado |
|---|---|
| 200 | Aplicada o ya aplicada, con resultado explícito |
| 400 | Cabecera ausente/inválida o cuerpo con campos no admitidos |
| 401 | Falta autenticación válida |
| 403 | Rol no autorizado, membresía inactiva/ajena o contexto inactivo |
| 404 | Plantilla inexistente |
| 409 | Conflicto transaccional recuperable; consultar y reintentar |
| 422 | Plantilla inactiva, actividad incompatible, configuración inválida o colisión de nombres |

La compatibilidad se comprueba también al repetir. `OTRO` conserva la semántica genérica del servicio para plantillas internas; las tres iniciales son específicas de su actividad. Una colisión de nombres sin recibo previo devuelve 422 y revierte todo: no se da por aplicada una plantilla por parecido de nombres ni se modifican controles existentes. Las aplicaciones realizadas con versiones anteriores, sin recibo, requieren revisión si sus nombres colisionan. No se implementa actualización de una plantilla aplicada a otra versión.

## Activar controles que necesitan límites propios

El catálogo **no contiene límites sanitarios numéricos de ejemplo**. Los controles numéricos nacen con `activa: false`, `requiereLimites: true` y límites nulos. No se programan automáticamente ni se pueden utilizar para registrar hasta configurarlos y activarlos. La marca `requiereLimites` es de solo lectura en la API y persiste en una columna independiente del JSON de configuración.

Antes de activar es obligatorio tener `tipoRespuesta: numero`, al menos un límite, una unidad y unas instrucciones no vacías; los límites deben ser decimales válidos y estar ordenados. Se permite un único límite para procesos con un umbral unilateral. Las validaciones de entidad, de dominio y un CHECK PostgreSQL protegen la activación; borrar los límites o cambiar a respuesta booleana mientras está activa también se rechaza. Desactivar temporalmente permite editarla por pasos. `configuracionPendiente` informa de la falta de estos datos, aunque una tarea ya configurada puede seguir inactiva hasta su activación expresa.

Ejemplo HTTP **esquemático**, que debe completarse con los valores aprobados para el establecimiento (los marcadores no son valores JSON válidos para el endpoint):

```http
PATCH /api/tareas/51 HTTP/1.1
Authorization: Bearer <JWT>
X-Establecimiento-Id: 12
Content-Type: application/merge-patch+json

{
  "limiteMinimo": "<decimal validado, o usar null>",
  "limiteMaximo": "<decimal validado, o usar null>",
  "unidad": "°C",
  "instrucciones": "<producto, equipo, método de medida y procedimiento aprobado>",
  "activa": true
}
```

El backend comprueba coherencia estructural; la adecuación técnica de los límites y del procedimiento debe decidirla el establecimiento. Las horas iniciales (09:00/18:00) y la revisión semanal de mantenimiento son propuestas organizativas editables, no frecuencias sanitarias universales. Hay que adaptar también productos, equipos, frecuencias y tareas a los procesos reales. Esta base inicial no pretende cubrir un plan APPCC completo.

Aplicar crea **definiciones**, sin ejecuciones ni registros ficticios. Tras revisar la configuración, las tareas recurrentes se materializan mediante `app:tareas:procesar`; las tareas `BAJO_DEMANDA` se programan con `POST /api/tareas/{id}/programaciones`, conforme al [ciclo operativo](ciclo-operativo-tareas.md).

## Fotografía en toda no conformidad

`requiereFotoNoConforme` vale siempre `true`: el setter rechaza desactivarlo, la migración actualiza todas las configuraciones existentes y PostgreSQL impide guardarlo a `false`. El servicio de registro exige fotografía de forma incondicional cuando el resultado es no conforme, incluso si falta configuración o se altera un objeto en memoria. La regla se aplica a controles numéricos, booleanos y estructurados; los dos primeros recalculan conformidad. Un control estructurado conserva su evaluación manual explícita.

Se mantiene el [flujo privado de evidencias](evidencias-registros.md):

```http
POST /api/evidencias/subidas HTTP/1.1
Authorization: Bearer <JWT>
X-Establecimiento-Id: 12
Content-Type: multipart/form-data; boundary=appcc

--appcc
Content-Disposition: form-data; name="tipo"

foto
--appcc
Content-Disposition: form-data; name="archivo"; filename="control.jpg"
Content-Type: image/jpeg

<bytes reales JPEG>
--appcc--
```

Después se consume el token recibido dentro del registro (ejemplo de control booleano):

```http
POST /api/registros HTTP/1.1
Authorization: Bearer <JWT>
X-Establecimiento-Id: 12
Content-Type: application/ld+json

{
  "tareaProgramada": "/api/tareas-programadas/70",
  "datos": {"resultado": false},
  "observaciones": "Incumplimiento detectado durante la revisión.",
  "evidencias": [{"token": "<token recibido>", "tipo": "foto"}],
  "confirmarRegistro": true
}
```

Sin foto válida devuelve **422** y conserva la ejecución pendiente/vencida, sin registro, evidencia definitiva ni incidencia nueva. Un PDF no satisface el requisito. Se mantienen validación de contenido y metadatos, autor/establecimiento, expiración, consumo único, almacenamiento privado y compensación de archivos ante rollback. Los controles conformes siguen permitiendo registro sin foto. Los registros históricos no se modifican ni se completan con fotografías inventadas: la obligación se aplica a los nuevos registros de todos los establecimientos.

## Comprobación

```console
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/console doctrine:schema:validate --env=test
php bin/phpunit
```

Las pruebas usan PostgreSQL real con la protección de base autorizada del proyecto. Incluyen carga repetida, las tres actividades, recibos idempotentes, rollback tardío, permisos, aislamiento, conflictos de nombres, configuración pendiente, migración de configuraciones antiguas, controles numéricos/booleanos/estructurados con y sin foto y PDF. Dos procesos HTTP reales esperan un bloqueo PostgreSQL para verificar la concurrencia y la revocación de permisos durante la espera.
