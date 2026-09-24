# Entrega del módulo Incidencias APPCC

Base revisada: `698afd5` (`main`), árbol limpio antes de comenzar. Validación local: 24 de septiembre de 2026. Los cambios se entregan sin commit ni publicación.

## Alcance cerrado

- Nueva entrada **Incidencias** y ruta privada `/incidencias`, con el diseño responsive existente. El detalle se abre dentro de esa pantalla; volver conserva filtros y página. Cambiar de establecimiento descarta selección, filtros, resultados y borradores.
- Listado paginado por servidor (20 elementos), estado, gravedad, número de registro de origen, rango de apertura y orden de apertura. Las fechas de día completo se convierten a instantes con zona usando los mismos helpers de Agenda. El establecimiento procede exclusivamente del contexto autorizado, no de un filtro libre ni de parámetros de URL.
- Detalle con título, descripción, gravedad, estado, apertura y cierre. Consulta de incidencias manuales sin registro asociado.
- Registro APPCC de origen en solo lectura: control, ejecución, autor, fecha, conformidad persistida, valor numérico o datos estructurados, observaciones y confirmación. No recalcula la conformidad ni modifica el registro histórico.
- Acciones correctivas e historial de estados con paginación independiente, fechas y autores. Incluye resultado de acciones y comentarios históricos cuando existen. Si un autor histórico ya no puede consultarse (404), conserva su identificador; otros errores de acceso no se ocultan.
- Alta de acción con descripción y resultado opcional. Identidad y fechas las determina Symfony. Las acciones históricas son de solo lectura.
- Transiciones existentes: `abierta → en_proceso`, `abierta → resuelta`, `en_proceso → resuelta`. Al resolver se muestra la fecha de cierre devuelta por Symfony y desaparecen las operaciones de estado/alta de acciones.
- Después de cada intento de escritura se vuelve a consultar la incidencia, registro, acciones, historial y listado. No hay actualización optimista ni reintentos automáticos de escritura. Una relectura fallida retira los formularios operativos hasta recuperar datos válidos.
- Flujo Agenda → Registro preservado: no se modifican sus componentes, contratos ni servicios. La incidencia se genera únicamente en el backend desde el registro no conforme. En su prueba Playwright solo cambia la preparación de la ejecución demo: un helper compartido evita colisiones de tarea/fecha entre pruebas repetidas; las acciones y aserciones originales se conservan.

## Contratos comprobados y utilizados

Fuentes: `backend/src/Entity/{Incidencia,AccionCorrectiva,HistorialIncidencia,RegistroAPPCC}.php`, `IncidenciaService`, `AccionCorrectivaService`, `TenantAuthorization` y [consultas del MVP](../../backend/docs/consultas-mvp.md). No hay cambios en Symfony, endpoints nuevos, migraciones ni dependencias nuevas.

| Método y ruta                         | Uso / payload                                                                                                                     |
| ------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `GET /api/incidencias`                | `page`, `itemsPerPage`, `estado`, `gravedad`, `registro` como IRI canónica, `fechaApertura[after/before]`, `order[fechaApertura]` |
| `GET /api/incidencias/{id}`           | Detalle y relectura                                                                                                               |
| `GET /api/registros/{id}`             | Registro de origen                                                                                                                |
| `GET /api/tareas/{id}`                | Nombre del control asociado, incluso histórico/inactivo                                                                           |
| `GET /api/usuarios/{id}`              | Nombre de autores autorizados en el establecimiento actual                                                                        |
| `GET /api/incidencias/{id}/acciones`  | Paginación, orden del servidor                                                                                                    |
| `GET /api/incidencias/{id}/historial` | Paginación y `order[createdAt]=asc`                                                                                               |
| `PATCH /api/incidencias/{id}`         | Exclusivamente `{estado: "en_proceso" \| "resuelta"}`, `application/merge-patch+json`                                             |
| `POST /api/acciones-correctivas`      | Exclusivamente `{incidencia: "/api/incidencias/ID", descripcion, resultado: string \| null}`                                      |

Se reutilizan `ApiClient`, `ApiError`, `readCollection`, proveedores y guards. Cada recurso operativo exige Bearer JWT y `X-Establecimiento-Id`, con `cache: no-store` y timeout de 15 segundos por petición. Los parsers comprueban tipos, IRI canónicas, ID solicitado, establecimiento y padre de acciones/historial. Nunca se siguen enlaces externos de paginación con credenciales: la siguiente página se construye con el endpoint conocido.

## Permisos y decisiones de dominio

| Rol de la membresía actual | Consultar | Añadir acción en incidencia abierta/en proceso | Cambiar estado |
| -------------------------- | --------- | ---------------------------------------------- | -------------- |
| admin                      | Sí        | Sí                                             | Sí             |
| responsable                | Sí        | Sí                                             | Sí             |
| trabajador                 | Sí        | Sí                                             | No             |
| auditor                    | Sí        | No                                             | No             |

La interfaz proyecta los permisos de `TenantAuthorization` y los destinos que ya admite `IncidenciaService`; Symfony sigue autorizando y validando cada petición. No se consulta ni replica en Next la configuración `permitirCerrarIncidenciaSinAccion`: se permite solicitar la resolución y se muestra el 422 del servidor si necesita una acción previa. Tampoco se calculan fechas, autorías ni entradas de historial en el cliente.

**Observaciones:** el contrato de incidencia tiene `descripcion`, no `observaciones`. Se muestran la descripción de la incidencia, las observaciones originales del registro, el resultado de las acciones y los comentarios históricos. No se envía un campo inventado ni se ofrece escribir comentarios en el historial, cuyo contrato es de solo lectura.

## Errores y cancelación

| Caso                           | Comportamiento                                                                                                                                                              |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 401                            | El cliente central invalida la sesión, borra JWT, cancela pendientes y retira el contenido privado; el guard lleva al login.                                                |
| 403                            | Mensaje de permisos y «Actualizar mis accesos». Symfony puede revocar permisos después de cargar `/api/me`.                                                                 |
| 404                            | Recurso no encontrado y recuperación de accesos; nunca se carga un recurso de otro tenant. La excepción de autor histórico se limita a su etiqueta, sin cambiar relaciones. |
| 409                            | Conflicto visible, consulta automática y bloqueo de nuevos envíos hasta pulsar «Volver a consultar la incidencia».                                                          |
| 422                            | Mensaje/violations seguros del servidor y conservación del borrador de acción. Se consulta el estado antes de otro intento.                                                 |
| Red, timeout o 5xx al escribir | La operación puede haberse completado: consulta sin reenviar, bloqueo y comprobación explícita antes de otro envío.                                                         |
| Error al leer                  | Mensaje seguro; recuperación explícita y sin mostrar datos anteriores como actuales.                                                                                        |

`AbortController` por consulta y por detalle; el cliente central cancela todas las peticiones al cambiar de establecimiento o sesión. Los resultados tardíos, incluidos 401, no afectan al nuevo contexto. Salir del detalle aborta su escritura pendiente; no implica que Symfony haya cancelado una operación ya recibida. Al volver siempre se consulta de nuevo.

## Pruebas y validación

`tests/incidencias.test.tsx`: Vitest/Testing Library con el proveedor y cliente HTTP reales, dobles exclusivamente en `fetch`. Permisos de los cuatro roles, filtros/paginación, registro e historial, todas las transiciones expuestas, payloads mínimos/cabeceras, doble envío, relectura tras éxito/error, 401/403/404/409/422/red/5xx, borradores, datos ajenos y cambio de tenant durante listado/detalle/escritura.

`tests/live/incidencias.spec.ts`: sin interceptaciones HTTP. Prepara una ejecución mediante el endpoint existente; entra como responsable por login, navega a Agenda, registra una no conformidad con fotografía, comprueba una única incidencia, la filtra por registro, abre el origen, añade una acción, pasa a en proceso y resuelve. Comprueba autoría, fecha de cierre, tres estados del historial, reconsulta del listado y ausencia de desbordamiento horizontal. Escritorio y móvil con Edge. Conserva los históricos demo creados.

Preparación y ejecución PowerShell:

```powershell
# Desde backend/; entorno dev local ya configurado.
Set-ExecutionPolicy -Scope Process Bypass -Force
. ./bin/local-env.ps1
$env:APP_ENV = 'dev'
php bin/console app:demo:seed

# Desde frontend/; puertos 8011 y 3101 libres.
Set-ExecutionPolicy -Scope Process Bypass -Force
. ../backend/bin/local-env.ps1
$env:PLAYWRIGHT_CHANNEL = 'msedge'
npm.cmd run lint
npm.cmd run typecheck
npm.cmd test
npm.cmd run test:live
npm.cmd run test:e2e
npm.cmd run build
```

| Comprobación | Resultado final |
| --- | --- |
| `npm.cmd run lint` | Correcto, sin avisos |
| `npm.cmd run typecheck` | Correcto |
| `npm.cmd test` | **111 pruebas correctas**, 35 nuevas de incidencias; 6 archivos |
| `npm.cmd run test:live` con Edge | **4 pruebas reales correctas**, incidencias y registro en escritorio/móvil |
| `npm.cmd run test:e2e` con Edge | **4 pruebas correctas**, sesión y navegación en escritorio/móvil |
| `npm.cmd run build` | Build de producción correcto, incluida `/incidencias` |
| `php bin/console doctrine:schema:validate` | Mapping y base local sincronizados |
| `php bin/console app:demo:seed` | Establecimiento demo reutilizado (ID 2) |
| `git diff --check` | Correcto |

Se inspeccionaron las capturas completas del detalle en escritorio y móvil. Se generan en `frontend/test-results/`; una copia de esta validación se conserva en `backend/var/validacion-incidencias/`, excluido de Git. Las pruebas no guardan tokens en trazas. La suite real escribe datos demo y conserva los históricos. No se ejecutó CI remota ni se repitió PHPUnit: no hay cambios de backend.

Notas de reproducibilidad: Playwright se ejecutó fuera del aislamiento de procesos para permitir el cierre de sus servidores hijos en Windows. El detalle de la prueba real usa una espera de aserción de 20 segundos porque PHP de desarrollo sirve en serie varias lecturas; el timeout de producción sigue siendo 15 segundos por petición. El helper de preparación compartido escoge otro segundo únicamente tras un 409 confirmado, hasta diez intentos; nunca reenvía escrituras ante red/timeout/5xx. Esto evita colisiones entre las ejecuciones demo repetidas sin borrar registros.

Se observó un fallo intermitente en la prueba Vitest existente de cancelación de subida de Registro al ejecutar simultáneamente Vitest, lint, typecheck y navegador. La ejecución final de Vitest por separado pasó las 111 pruebas; no se modificaron esa prueba ni la lógica de Registro. Conviene vigilar esa sincronización si CI reproduce el fallo bajo carga.

## Pendiente y límites del ciclo

El ciclo **no conformidad registrada → consulta de incidencia → acción correctiva → seguimiento → resolución con historial** queda implementado en el frontend, sujeto a las reglas y permisos actuales del servidor.

Quedan fuera de esta entrega de incidencias:

- Crear incidencias manualmente desde una pantalla y editar título, descripción o gravedad, aunque existen contratos de backend para ello. Las incidencias manuales existentes sí se consultan y se gestionan.
- Visualización/descarga de archivos históricos del registro o de la incidencia. Aquí se visualizan los datos del registro y su confirmación; no se añaden subidas de evidencias a incidencias.
- Enlaces directos al detalle por URL: la selección vive dentro de `/incidencias`, como el formulario de Registro dentro de Agenda.

No son operaciones disponibles en el dominio actual: reabrir una incidencia resuelta, retroceder de en proceso a abierta, modificar/borrar acciones históricas, escribir manualmente el historial o añadir estados como «verificada». Requerirían ampliar primero el backend; no se han simulado en Next.

El resto del MVP (pantallas generales de históricos/evidencias, tareas/plantillas, usuarios, configuración y KPIs) mantiene el alcance pendiente de la entrega anterior. Offline/PWA y notificaciones siguen fuera de alcance.
