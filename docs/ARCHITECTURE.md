# Arquitectura real

Referencia auditada: `main` en `3c92592536d9a21bca1288ad60e9110d516ad66e`, más las correcciones descritas en [DEVELOPMENT](DEVELOPMENT.md). Symfony expone la API y protege el dominio; Next.js consume contratos HTTP y no accede a PostgreSQL directamente.

## Relaciones

```text
EntidadFiscal
 ├── ConfiguracionEntidadFiscal (1:1)
 └── Establecimiento
      ├── ConfiguracionEstablecimiento (1:1)
      ├── UsuarioEstablecimiento ── Usuario
      ├── PuntoControl
      ├── PlanControl
      │    └── TareaAPPCC ── PuntoControl (opcional)
      │         └── TareaProgramada
      │              └── RegistroAPPCC (0..1 por ejecución)
      │                   ├── Evidencia
      │                   └── Incidencia (0..1 vinculada)
      │                        ├── HistorialIncidencia
      │                        ├── AccionCorrectiva
      │                        └── Evidencia (padre alternativo)
      └── AplicacionPlantillaAPPCC ── PlantillaAPPCC (catálogo global)
```

`PuntoControl` pertenece directamente al establecimiento: **no tiene relación directa con PlanControl**. `TareaAPPCC` une plan, establecimiento y punto opcional. Ejecuciones, registros e incidencias también conservan establecimiento, con validaciones de coherencia. Una incidencia puede ser manual y no tener registro. `Evidencia` tiene exactamente un padre, registro XOR incidencia, protegido por CHECK. `Usuario` participa en varios establecimientos mediante membresías únicas usuario/establecimiento.

`RegistroAPPCC.tareaProgramada` es OneToOne. `Incidencia.registro` está mapeada como ManyToOne, pero el índice parcial `uniq_incidencia_registro` impone como máximo una incidencia por registro no nulo; no deducir cardinalidad solo de la colección inversa. `uniq_plantilla_local` impide duplicar aplicaciones. Las FK históricas usan RESTRICT, sin borrado en cascada.

## Peticiones y tenant

`POST /api/login_check` autentica email/password con LexikJWT. `UsuarioChecker` rechaza cuentas inactivas o sin contraseña válida. `GET /api/me` devuelve identidad y membresías vigentes sin cabecera tenant. Login, onboarding, aceptación de invitación y contraseña inicial tienen las excepciones públicas explícitas de `security.yaml`; el resto de `/api` exige autenticación.

`CurrentEstablecimientoContext` valida `X-Establecimiento-Id` como entero positivo y comprueba usuario, membresía, establecimiento y entidad fiscal activos. `TenantExtension` acota consultas Doctrine; `IriTenantResolver` y `TenantAuthorization` validan recursos y relaciones. Los DTO de escritura obtienen autor y contexto del servidor. Un recurso de otro tenant no se habilita conociendo su ID. El catálogo de plantillas es global autenticado; aplicarlo sí requiere tenant.

| Operación | admin | responsable | trabajador | auditor |
|---|---|---|---|---|
| Agenda, registros, incidencias, evidencias y catálogo | Sí | Sí | Sí | Sí |
| Registrar control, subir foto, añadir acción correctiva | Sí | Sí | Sí | No |
| Crear incidencia manual | Sí | Sí | Sí | No |
| Cambiar estado/resolver incidencia | Sí | Sí | No | No |
| Planes, puntos, tareas, ejecuciones, configuración y aplicar plantilla | Sí | Sí | No | No |
| Gestionar membresías/invitaciones | Sí | No | No | No |
| Leer datos/configuración fiscal y membresías | Sí | Sí | No | Sí |

Cada permiso depende además de membresía y contexto válidos; los roles globales Symfony no sustituyen estas reglas. La política está en `TenantAuthorization`, los processors y los servicios. La gestión de usuarios revalida ADMIN bajo bloqueo; la aplicación de plantillas revalida ADMIN/RESPONSABLE después de esperar el bloqueo.

## Ejecución, registro y no conformidad

`GeneradorTareasProgramadasService` materializa frecuencia diaria, semanal y mensual según zona fiscal y calendario. `TareaProgramadaService` gestiona ejecuciones bajo demanda, asignación, omisión y vencidas. `CicloOperativoTareasService`, expuesto por `app:tareas:procesar`, combina generación y vencimiento. Se necesitan ejecuciones periódicas del comando; abrir la Agenda no sustituye el planificador. Las restricciones y bloqueos evitan duplicados y protegen cambios concurrentes.

`RegistrarControlProcessor` adapta `CrearRegistroInput` al caso de uso `RegistroAPPCCService`. Este bloquea la ejecución, valida tenant/autor/estado/plazo, calcula conformidad numérica o booleana y valida campos estructurados. Los decimales se comparan con precisión fija; los datos estructurados requieren una evaluación manual explícita. Registro, tarea completada, confirmación e incidencia automática se confirman en una transacción.

Todo registro nuevo no conforme exige una foto consumible. `requiereFotoNoConforme` está fijado a true por modelo y PostgreSQL desde `Version20260922090000`; no es una opción desactivable. `requiereObservacionNoConforme`, `requiereFirmaRegistro`, plazos y generación automática de incidencia respetan configuración. La demo activa observación, confirmación e incidencia automática.

La confirmación usa `confirmarRegistro: true`, el usuario JWT, reloj del servidor y versión de declaración. No hay firma dibujada. Aunque se permita no confirmar según configuración, los registros persistidos siguen siendo históricos: API, dominio y triggers impiden UPDATE/DELETE. No recalcular resultados anteriores con límites nuevos.

## Evidencias y transacciones

`SubidaEvidenciaService` valida MIME real, tamaño, imagen/PDF, nombre y propietario; crea una subida temporal privada con token opaco y caducidad. El registro consume cada token una sola vez, validando usuario, tenant, tipo, hash y archivo bajo bloqueo. `LocalEvidenciaStorage` mantiene temporales y definitivos fuera de `public/`. No se crea evidencia histórica enviando metadatos arbitrarios.

El movimiento de archivos tiene compensación ante rollback observado, pero disco y PostgreSQL no forman una transacción distribuida. Una caída abrupta exige conciliación con `app:evidencias:verificar`; `app:evidencias:limpiar-temporales` elimina únicamente temporales caducados. Respaldar conjuntamente BD y volumen persistente.

`EvidenciaController` autentica y valida tenant/permisos antes de transmitir. La respuesta usa MIME validado, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, caché privada no-store y Vary por autorización/tenant. La API no expone `storageKey`, rutas físicas ni `hashSha256`. La URL no sustituye JWT/cabeceras. El cliente descarga por fetch autenticado y revoca la URL blob.

## Incidencias y plantillas

`IncidenciaService` reutiliza la incidencia existente del registro NC y anota transiciones en historial append-only. Los estados son abierta, en proceso y resuelta. El cierre exige acción correctiva salvo permiso explícito de configuración; no se reabre una resuelta con PATCH genérico ni se añaden acciones después de resolver. La concurrencia de estados se comprueba bajo bloqueo.

`PlantillaAPPCCService` carga los JSON restaurante/obrador/catering por código estable. La aplicación bloquea el establecimiento, valida actividad/rol y guarda definiciones y recibo en una sola transacción. Repetir devuelve la aplicación original, sin duplicar ni sobrescribir adaptaciones. El recibo es una instantánea inicial: consultar la tarea para conocer su estado actual.

Los controles con `requiereLimites` nacen inactivos y pendientes. Para activarlos hacen falta respuesta numérica, al menos un límite válido, unidad e instrucciones; si hay dos límites deben estar ordenados. Aplicar no crea ejecuciones: después intervienen el ciclo recurrente o la programación bajo demanda.

## Frontend y contratos

`SessionProvider`/`SessionStore` gestionan JWT, `/api/me`, selección y recuperación. Token y establecimiento se guardan en sessionStorage. Salir o recibir 401 vigente limpia sesión; cambiar contexto cancela peticiones y desmonta los espacios de trabajo por tenant. Se descartan respuestas tardías de contextos anteriores.

`lib/api` construye peticiones, restringe rutas/relaciones y convierte errores. `features/*/contracts.ts` valida respuestas en ejecución: TypeScript por sí solo no valida JSON externo. Consultas usan estados loading/error/vacío, cancelación y reintento; escrituras inciertas requieren consultar antes de repetir. Los indicadores usan los totales filtrados de API, con «hoy» según zona del dispositivo, tal como indica la pantalla.

## Contratos detallados conservados

- [Modelo y migraciones](../backend/docs/modelo-mvp.md), [consultas](../backend/docs/consultas-mvp.md), [ciclo operativo](../backend/docs/ciclo-operativo-tareas.md).
- [Sesión](../backend/docs/contexto-sesion.md), [usuarios](../backend/docs/usuarios-onboarding.md), [configuración](../backend/docs/configuracion-saas.md).
- [Evidencias](../backend/docs/evidencias-registros.md), [plantillas](../backend/docs/plantillas-iniciales.md), [incidencias frontend](../frontend/docs/incidencias.md), [agenda](../frontend/docs/agenda.md).

Los informes de entrega y validación de esas carpetas documentan su fecha, no el cierre actual del MVP. Consultar [MVP](MVP.md) para ese estado.
